<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Core\SalesChannel\GuestMerge;

use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeConfirmPreviewResponse;
use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeExecuteResponse;
use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeInitiateResponse;
use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeStatusResponse;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract class AbstractGuestMergeRoute
{
    abstract public function getDecorated(): static;

    abstract public function status(SalesChannelContext $context, CustomerEntity $customer): GuestMergeStatusResponse;

    abstract public function initiate(SalesChannelContext $context, CustomerEntity $customer): GuestMergeInitiateResponse;

    abstract public function confirmPreview(string $token, SalesChannelContext $context, CustomerEntity $customer): GuestMergeConfirmPreviewResponse;

    abstract public function confirmExecute(string $token, SalesChannelContext $context, CustomerEntity $customer): GuestMergeExecuteResponse;
}
