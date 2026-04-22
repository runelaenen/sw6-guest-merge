<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Exception;

use Shopware\Core\Framework\ShopwareHttpException;

class MergeException extends ShopwareHttpException
{
    public function getErrorCode(): string
    {
        return 'LAENEN_GUEST_MERGE__GENERAL_ERROR';
    }
}
