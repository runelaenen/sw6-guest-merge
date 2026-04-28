<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response;

use Laenen\GuestMerge\Dto\GuestCandidatesDto;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

class GuestMergeStatusResponse extends StoreApiResponse
{
    public function __construct(
        private readonly GuestCandidatesDto $candidates,
        private readonly ?array $latestRequest,
        private readonly bool $allowSelfService,
    ) {
        parent::__construct(new ArrayStruct([
            'candidates' => $candidates->toArray(),
            'latestRequest' => $latestRequest,
            'config' => ['allowSelfService' => $allowSelfService],
        ], 'guest_merge_status'));
    }

    public function getCandidates(): GuestCandidatesDto
    {
        return $this->candidates;
    }

    public function getLatestRequest(): ?array
    {
        return $this->latestRequest;
    }

    public function isAllowSelfService(): bool
    {
        return $this->allowSelfService;
    }
}
