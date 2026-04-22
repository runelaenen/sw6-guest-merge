<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Dto;

class MergeResult
{
    /**
     * @param string[] $deletedGuestIds
     * @param string[] $movedOrderIds
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $authCustomerId,
        public readonly string $email,
        public readonly int $movedOrderCount,
        public readonly array $movedOrderIds,
        public readonly array $deletedGuestIds,
        public readonly string $verificationMethod,
    ) {}

    public function toArray(): array
    {
        return [
            'requestId' => $this->requestId,
            'authCustomerId' => $this->authCustomerId,
            'email' => $this->email,
            'movedOrderCount' => $this->movedOrderCount,
            'movedOrderIds' => $this->movedOrderIds,
            'deletedGuestCustomerIds' => $this->deletedGuestIds,
            'verificationMethod' => $this->verificationMethod,
        ];
    }
}
