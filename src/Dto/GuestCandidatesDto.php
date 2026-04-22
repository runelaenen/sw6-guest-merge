<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Dto;

class GuestCandidatesDto
{
    /**
     * @param string[] $guestCustomerIds Hex UUIDs
     * @param array<int, array{id:string, salesChannelId:string, createdAt:string}> $guestCustomers
     * @param array<int, array{salesChannelId:string, salesChannelName:?string, orderCount:int, totalAmount:float}> $bySalesChannel
     */
    public function __construct(
        public readonly string $email,
        public readonly array $guestCustomerIds,
        public readonly array $guestCustomers,
        public readonly int $orderCount,
        public readonly float $totalAmount,
        public readonly ?string $oldestOrderDate,
        public readonly ?string $newestOrderDate,
        public readonly array $bySalesChannel,
    ) {}

    public function isEmpty(): bool
    {
        return $this->orderCount === 0 || \count($this->guestCustomerIds) === 0;
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'guestCustomerIds' => $this->guestCustomerIds,
            'guestCustomerCount' => \count($this->guestCustomerIds),
            'orderCount' => $this->orderCount,
            'totalAmount' => $this->totalAmount,
            'oldestOrderDate' => $this->oldestOrderDate,
            'newestOrderDate' => $this->newestOrderDate,
            'bySalesChannel' => $this->bySalesChannel,
        ];
    }
}
