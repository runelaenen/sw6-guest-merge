<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Event;

use Shopware\Core\Framework\Context;
use Symfony\Contracts\EventDispatcher\Event;

class GuestOrderMergeConfirmedEvent extends Event
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $authCustomerId,
        public readonly string $verificationMethod,
        public readonly Context $context,
    ) {}
}
