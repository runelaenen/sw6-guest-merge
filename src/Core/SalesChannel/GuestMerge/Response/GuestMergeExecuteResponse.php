<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response;

use Laenen\GuestMerge\Dto\MergeResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

class GuestMergeExecuteResponse extends StoreApiResponse
{
    public function __construct(
        private readonly MergeResult $result,
    ) {
        parent::__construct(new ArrayStruct([
            'result' => $result->toArray(),
        ], 'guest_merge_execute'));
    }

    public function getResult(): MergeResult
    {
        return $this->result;
    }
}
