<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response;

use Laenen\GuestMerge\Dto\GuestCandidatesDto;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

class GuestMergeConfirmPreviewResponse extends StoreApiResponse
{
    public function __construct(
        private readonly string $token,
        private readonly array $request,
        private readonly GuestCandidatesDto $candidates,
    ) {
        parent::__construct(new ArrayStruct([
            'token' => $token,
            'request' => $request,
            'candidates' => $candidates->toArray(),
        ], 'guest_merge_confirm_preview'));
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getRequest(): array
    {
        return $this->request;
    }

    public function getCandidates(): GuestCandidatesDto
    {
        return $this->candidates;
    }
}
