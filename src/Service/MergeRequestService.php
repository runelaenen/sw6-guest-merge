<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Laenen\GuestMerge\Dto\GuestCandidatesDto;
use Laenen\GuestMerge\Event\GuestOrderMergeConfirmedEvent;
use Laenen\GuestMerge\Event\GuestOrderMergeRequestedEvent;
use Laenen\GuestMerge\Exception\InvalidTokenException;
use Laenen\GuestMerge\Exception\NoCandidatesException;
use Laenen\GuestMerge\Exception\RequestExpiredException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class MergeRequestService
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED    = 'failed';

    public const METHOD_LINK         = 'link';        // storefront link click
    public const METHOD_VERBAL_CODE  = 'verbal_code'; // CSR enters code customer reads
    public const METHOD_TRUSTED_CSR  = 'trusted_csr'; // direct merge by CSR (config-gated)

    public function __construct(
        private readonly Connection $connection,
        private readonly TokenGenerator $tokenGenerator,
        private readonly GuestOrderFinder $finder,
        private readonly SystemConfigReader $config,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @return array{
     *   id: string, token: string, shortCode: string, expiresAt: \DateTimeImmutable,
     *   candidates: GuestCandidatesDto
     * }
     */
    public function initiate(
        string $authCustomerHexId,
        ?string $adminUserHexId,
        Context $context,
        ?string $salesChannelHexId = null
    ): array {
        $candidates = $this->finder->findCandidatesFor($authCustomerHexId, $salesChannelHexId);

        if ($candidates->isEmpty()) {
            throw new NoCandidatesException($candidates->email);
        }

        $this->expirePendingFor($authCustomerHexId);

        $tokens = $this->tokenGenerator->generate();
        $requestId = Uuid::randomHex();
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify('+' . $this->config->tokenLifetimeHours() . ' hours');

        $this->connection->insert('laenen_guest_merge_request', [
            'id' => Uuid::fromHexToBytes($requestId),
            'customer_id' => Uuid::fromHexToBytes($authCustomerHexId),
            'email' => $candidates->email,
            'token_hash' => $tokens['tokenHash'],
            'short_code_hash' => $tokens['shortCodeHash'],
            'status' => self::STATUS_PENDING,
            'candidate_count' => \count($candidates->guestCustomerIds),
            'order_count_snapshot' => $candidates->orderCount,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.v'),
            'initiated_by_user_id' => $adminUserHexId !== null ? Uuid::fromHexToBytes($adminUserHexId) : null,
            'created_at' => $now->format('Y-m-d H:i:s.v'),
        ], [
            'id' => ParameterType::BINARY,
            'customer_id' => ParameterType::BINARY,
            'initiated_by_user_id' => ParameterType::BINARY,
        ]);

        $this->eventDispatcher->dispatch(
            new GuestOrderMergeRequestedEvent($requestId, $authCustomerHexId, $candidates, $context)
        );

        return [
            'id' => $requestId,
            'token' => $tokens['token'],
            'shortCode' => $tokens['shortCode'],
            'expiresAt' => $expiresAt,
            'candidates' => $candidates,
        ];
    }

    /**
     * Lookup a pending request by long URL token. Validates expiry and customer match.
     *
     * @return array<string, mixed> request row (keys camelCased)
     */
    public function loadPendingByToken(string $token, string $authCustomerHexId): array
    {
        $row = $this->fetchByHash('token_hash', $this->tokenGenerator->hash($token));
        $this->assertValidPending($row, $authCustomerHexId);
        return $row;
    }

    /**
     * Lookup a pending request by verbal short code. Validates expiry and customer match.
     */
    public function loadPendingByShortCode(string $code, string $authCustomerHexId): array
    {
        $normalized = $this->tokenGenerator->normalizeShortCode($code);
        $row = $this->fetchByHash('short_code_hash', $this->tokenGenerator->hash($normalized));
        $this->assertValidPending($row, $authCustomerHexId);
        return $row;
    }

    public function loadById(string $requestHexId): ?array
    {
        $row = $this->connection->fetchAssociative(
            $this->baseSelect() . ' WHERE id = :id LIMIT 1',
            ['id' => Uuid::fromHexToBytes($requestHexId)]
        );
        return $row ?: null;
    }

    public function loadLatestForCustomer(string $authCustomerHexId): ?array
    {
        $row = $this->connection->fetchAssociative(
            $this->baseSelect() . ' WHERE customer_id = :cid ORDER BY created_at DESC LIMIT 1',
            ['cid' => Uuid::fromHexToBytes($authCustomerHexId)]
        );
        return $row ?: null;
    }

    public function markConfirmed(string $requestHexId, string $verificationMethod, Context $context): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $affected = $this->connection->executeStatement(
            'UPDATE laenen_guest_merge_request
                SET status = :confirmed,
                    verification_method = :method,
                    confirmed_at = :now,
                    updated_at = :now
              WHERE id = :id AND status = :pending',
            [
                'confirmed' => self::STATUS_CONFIRMED,
                'method' => $verificationMethod,
                'now' => $now,
                'id' => Uuid::fromHexToBytes($requestHexId),
                'pending' => self::STATUS_PENDING,
            ]
        );

        if ($affected === 0) {
            // Either already confirmed (double-click) or status moved on; treat as conflict
            throw new InvalidTokenException('Request is no longer pending.');
        }

        $row = $this->loadById($requestHexId);
        if ($row !== null) {
            $this->eventDispatcher->dispatch(
                new GuestOrderMergeConfirmedEvent(
                    $requestHexId,
                    bin2hex($row['customer_id']),
                    $verificationMethod,
                    $context
                )
            );
        }
    }

    public function markCompleted(string $requestHexId, int $movedOrderCount, array $deletedGuestHexIds): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->executeStatement(
            'UPDATE laenen_guest_merge_request
                SET status = :completed,
                    moved_order_count = :moved,
                    deleted_guest_ids = :deleted,
                    completed_at = :now,
                    updated_at = :now
              WHERE id = :id',
            [
                'completed' => self::STATUS_COMPLETED,
                'moved' => $movedOrderCount,
                'deleted' => json_encode($deletedGuestHexIds),
                'now' => $now,
                'id' => Uuid::fromHexToBytes($requestHexId),
            ]
        );
    }

    public function markFailed(string $requestHexId, string $errorMessage): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->executeStatement(
            'UPDATE laenen_guest_merge_request
                SET status = :failed,
                    error_message = :err,
                    updated_at = :now
              WHERE id = :id',
            [
                'failed' => self::STATUS_FAILED,
                'err' => mb_substr($errorMessage, 0, 65000),
                'now' => $now,
                'id' => Uuid::fromHexToBytes($requestHexId),
            ]
        );
    }

    public function cancel(string $requestHexId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->executeStatement(
            'UPDATE laenen_guest_merge_request
                SET status = :cancelled, updated_at = :now
              WHERE id = :id AND status = :pending',
            [
                'cancelled' => self::STATUS_CANCELLED,
                'now' => $now,
                'id' => Uuid::fromHexToBytes($requestHexId),
                'pending' => self::STATUS_PENDING,
            ]
        );
    }

    public function expirePendingFor(string $authCustomerHexId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $this->connection->executeStatement(
            'UPDATE laenen_guest_merge_request
                SET status = :expired, updated_at = :now
              WHERE customer_id = :cid AND status = :pending',
            [
                'expired' => self::STATUS_EXPIRED,
                'now' => $now,
                'cid' => Uuid::fromHexToBytes($authCustomerHexId),
                'pending' => self::STATUS_PENDING,
            ]
        );
    }

    public function expireOverdue(): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        return (int)$this->connection->executeStatement(
            'UPDATE laenen_guest_merge_request
                SET status = :expired, updated_at = :now
              WHERE status = :pending AND expires_at < :now',
            ['expired' => self::STATUS_EXPIRED, 'pending' => self::STATUS_PENDING, 'now' => $now]
        );
    }

    private function fetchByHash(string $column, string $hash): ?array
    {
        $sql = $this->baseSelect() . " WHERE {$column} = :h LIMIT 1";
        $row = $this->connection->fetchAssociative($sql, ['h' => $hash]);
        return $row ?: null;
    }

    private function assertValidPending(?array $row, string $authCustomerHexId): void
    {
        if ($row === null) {
            throw new InvalidTokenException();
        }

        if (bin2hex($row['customer_id']) !== $authCustomerHexId) {
            throw new InvalidTokenException('Token does not match the current customer.');
        }

        if ($row['status'] !== self::STATUS_PENDING) {
            throw new InvalidTokenException('This request has already been used or is no longer valid.');
        }

        if (new \DateTimeImmutable($row['expires_at']) < new \DateTimeImmutable()) {
            throw new RequestExpiredException();
        }
    }

    private function baseSelect(): string
    {
        return 'SELECT id, customer_id, email, status, verification_method,
                       candidate_count, order_count_snapshot, moved_order_count,
                       deleted_guest_ids, expires_at, confirmed_at, completed_at,
                       initiated_by_user_id, error_message, created_at, updated_at
                  FROM laenen_guest_merge_request';
    }
}
