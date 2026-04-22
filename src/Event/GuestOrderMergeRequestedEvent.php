<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Event;

use Laenen\GuestMerge\Dto\GuestCandidatesDto;
use Shopware\Core\Framework\Context;
use Symfony\Contracts\EventDispatcher\Event;

class GuestOrderMergeRequestedEvent extends Event
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $authCustomerId,
        public readonly GuestCandidatesDto $candidates,
        public readonly Context $context,
    ) {}
}
