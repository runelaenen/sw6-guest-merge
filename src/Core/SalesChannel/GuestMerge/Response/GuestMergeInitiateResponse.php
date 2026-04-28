<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

class GuestMergeInitiateResponse extends StoreApiResponse
{
    public function __construct(
        private readonly string $requestId,
        private readonly \DateTimeImmutable $expiresAt,
        private readonly int $candidateOrderCount,
        private readonly int $candidateGuestCount,
    ) {
        parent::__construct(new ArrayStruct([
            'requestId' => $requestId,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            'candidateOrderCount' => $candidateOrderCount,
            'candidateGuestCount' => $candidateGuestCount,
        ], 'guest_merge_initiate'));
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCandidateOrderCount(): int
    {
        return $this->candidateOrderCount;
    }

    public function getCandidateGuestCount(): int
    {
        return $this->candidateGuestCount;
    }
}
