<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Service;

use Doctrine\DBAL\Connection;
use Laenen\GuestMerge\Dto\MergeResult;
use Laenen\GuestMerge\Event\GuestOrderMergedEvent;
use Laenen\GuestMerge\Exception\MergeException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Performs the actual merge inside a single DB transaction.
 *
 * Strategy:
 *   1. Re-discover guest customer IDs by the AUTH customer's CURRENT email
 *      (not the snapshot on the request). Defends against email changes between
 *      initiate and confirm.
 *   2. UPDATE order_customer.customer_id (the only critical re-link).
 *   3. Recompute aggregates on the auth customer.
 *   4. DAL-delete the guest customer rows so events fire & extensions react.
 *
 * What we deliberately do NOT touch:
 *   - order, order_line_item, order_address, order_transaction (snapshots stay)
 *   - order_customer.email/first_name/last_name/customer_number (historical fidelity)
 *   - the auth customer's default billing/shipping/payment
 *   - the auth customer's address book
 */
class GuestOrderMerger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly GuestOrderFinder $finder,
        private readonly CustomerAggregateRecalculator $aggregateRecalculator,
        private readonly MergeRequestService $requestService,
        private readonly EntityRepository $customerRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Execute the merge for a confirmed request.
     */
    public function executeForRequest(string $requestHexId, Context $context): MergeResult
    {
        $request = $this->requestService->loadById($requestHexId);
        if ($request === null) {
            throw new MergeException('Merge request not found.');
        }

        if ($request['status'] !== MergeRequestService::STATUS_CONFIRMED) {
            throw new MergeException(\sprintf(
                'Cannot execute merge: request is in status "%s".',
                $request['status']
            ));
        }

        $authCustomerHexId = bin2hex($request['customer_id']);
        $verificationMethod = (string)$request['verification_method'];

        try {
            return $this->doMerge(
                $requestHexId,
                $authCustomerHexId,
                $verificationMethod,
                $context
            );
        } catch (\Throwable $e) {
            $this->logger->error('Laenen guest merge failed', [
                'requestId' => $requestHexId,
                'customerId' => $authCustomerHexId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->requestService->markFailed($requestHexId, $e->getMessage());
            throw new MergeException('Merge failed: ' . $e->getMessage(), [], $e);
        }
    }

    /**
     * Direct merge path for trusted CSR scenarios. Creates a request row for audit
     * and immediately executes - no email verification step.
     *
     * Gated by config flag + admin ACL on the controller side.
     */
    public function executeDirectForTrustedCsr(
        string $authCustomerHexId,
        string $adminUserHexId,
        Context $context
    ): MergeResult {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $requestHexId = \Shopware\Core\Framework\Uuid\Uuid::randomHex();
        $email = $this->finder->fetchCustomerEmail($authCustomerHexId)
            ?? throw new MergeException('Customer not found.');
        $candidates = $this->finder->findCandidatesFor($authCustomerHexId);

        if ($candidates->isEmpty()) {
            throw new MergeException('No guest orders to merge.');
        }

        $tokens = (new TokenGenerator())->generate();

        $this->connection->insert('laenen_guest_merge_request', [
            'id' => Uuid::fromHexToBytes($requestHexId),
            'customer_id' => Uuid::fromHexToBytes($authCustomerHexId),
            'email' => $email,
            'token_hash' => $tokens['tokenHash'],          // unused but column is NOT NULL
            'short_code_hash' => $tokens['shortCodeHash'], // unused but column is NOT NULL
            'status' => MergeRequestService::STATUS_CONFIRMED,
            'verification_method' => MergeRequestService::METHOD_TRUSTED_CSR,
            'candidate_count' => \count($candidates->guestCustomerIds),
            'order_count_snapshot' => $candidates->orderCount,
            'expires_at' => $now,
            'confirmed_at' => $now,
            'initiated_by_user_id' => Uuid::fromHexToBytes($adminUserHexId),
            'created_at' => $now,
        ]);

        try {
            return $this->doMerge(
                $requestHexId,
                $authCustomerHexId,
                MergeRequestService::METHOD_TRUSTED_CSR,
                $context
            );
        } catch (\Throwable $e) {
            $this->logger->error('Laenen direct CSR merge failed', [
                'requestId' => $requestHexId,
                'customerId' => $authCustomerHexId,
                'adminUserId' => $adminUserHexId,
                'error' => $e->getMessage(),
            ]);
            $this->requestService->markFailed($requestHexId, $e->getMessage());
            throw new MergeException('Merge failed: ' . $e->getMessage(), [], $e);
        }
    }

    private function doMerge(
        string $requestHexId,
        string $authCustomerHexId,
        string $verificationMethod,
        Context $context
    ): MergeResult {
        $authEmail = $this->finder->fetchCustomerEmail($authCustomerHexId);
        if ($authEmail === null) {
            throw new MergeException('Authenticated customer no longer exists.');
        }

        // Acquire row-level lock on the auth customer to serialize concurrent merges
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'SELECT id FROM customer WHERE id = :id FOR UPDATE',
                ['id' => Uuid::fromHexToBytes($authCustomerHexId)]
            );

            // Re-discover at execution time (defends against drift since initiate)
            $guestHexIds = $this->finder->findGuestCustomerIdsByEmail(
                $authEmail,
                $authCustomerHexId
            );

            if ($guestHexIds === []) {
                $this->connection->commit();
                $this->requestService->markCompleted($requestHexId, 0, []);

                $result = new MergeResult(
                    requestId: $requestHexId,
                    authCustomerId: $authCustomerHexId,
                    email: $authEmail,
                    movedOrderCount: 0,
                    movedOrderIds: [],
                    deletedGuestIds: [],
                    verificationMethod: $verificationMethod,
                );
                $this->eventDispatcher->dispatch(new GuestOrderMergedEvent($result, $context));
                return $result;
            }

            $movedOrderIds = $this->relinkOrderCustomers($guestHexIds, $authCustomerHexId);

            // Recompute aggregates with the auth customer now owning the merged orders
            $this->aggregateRecalculator->recompute($authCustomerHexId, $context);

            // Delete guest customer rows via DAL (cascade handles addresses, recovery, etc.)
            $deletePayload = array_map(static fn(string $id) => ['id' => $id], $guestHexIds);
            $this->customerRepository->delete($deletePayload, $context);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $this->requestService->markCompleted(
            $requestHexId,
            \count($movedOrderIds),
            $guestHexIds
        );

        $result = new MergeResult(
            requestId: $requestHexId,
            authCustomerId: $authCustomerHexId,
            email: $authEmail,
            movedOrderCount: \count($movedOrderIds),
            movedOrderIds: $movedOrderIds,
            deletedGuestIds: $guestHexIds,
            verificationMethod: $verificationMethod,
        );

        $this->eventDispatcher->dispatch(new GuestOrderMergedEvent($result, $context));
        return $result;
    }

    /**
     * @param string[] $guestHexIds
     * @return string[] hex IDs of orders whose customer_id was updated
     */
    private function relinkOrderCustomers(array $guestHexIds, string $authCustomerHexId): array
    {
        $guestBin = array_map(static fn(string $h) => Uuid::fromHexToBytes($h), $guestHexIds);

        // Capture the affected order IDs first (for the event payload + return value)
        $affectedOrderIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(o.id))
               FROM `order` o
              INNER JOIN order_customer oc
                 ON oc.order_id = o.id
                AND oc.order_version_id = o.version_id
              WHERE oc.customer_id IN (:ids)
                AND o.version_id = :liveVersion',
            [
                'ids' => $guestBin,
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            [
                'ids' => Connection::PARAM_STR_ARRAY,
            ]
        );

        // Re-link in bulk. Snapshot fields (email/first_name/last_name/customer_number)
        // are deliberately NOT touched - they remain a faithful record of what the
        // buyer entered at checkout time.
        $this->connection->executeStatement(
            'UPDATE order_customer
                SET customer_id = :auth
              WHERE customer_id IN (:guests)',
            [
                'auth' => Uuid::fromHexToBytes($authCustomerHexId),
                'guests' => $guestBin,
            ],
            [
                'guests' => Connection::PARAM_STR_ARRAY,
            ]
        );

        return array_map(static fn($id) => (string)$id, $affectedOrderIds);
    }
}
