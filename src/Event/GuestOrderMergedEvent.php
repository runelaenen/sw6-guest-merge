<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Event;

use Laenen\GuestMerge\Dto\MergeResult;
use Shopware\Core\Framework\Context;
use Symfony\Contracts\EventDispatcher\Event;

class GuestOrderMergedEvent extends Event
{
    public function __construct(
        public readonly MergeResult $result,
        public readonly Context $context,
    ) {}
}
