<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Service;

use Doctrine\DBAL\Connection;
use Laenen\GuestMerge\Dto\GuestCandidatesDto;
use Laenen\GuestMerge\Dto\MergeResult;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class MergeNotificationMailer
{
    private const VERIFY_TYPE   = 'laenen_guest_merge_verify';
    private const COMPLETE_TYPE = 'laenen_guest_merge_completed';

    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly Connection $connection,
        private readonly SystemConfigReader $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendVerification(
        string $authCustomerHexId,
        string $token,
        string $shortCode,
        \DateTimeInterface $expiresAt,
        GuestCandidatesDto $candidates,
        Context $context
    ): void {
        $customer = $this->loadCustomer($authCustomerHexId, $context);
        if ($customer === null) {
            return;
        }

        $salesChannel = $this->loadSalesChannel($customer->getSalesChannelId(), $context);
        $confirmUrl = $this->buildConfirmUrl($salesChannel, $token);

        $template = $this->loadTemplate(self::VERIFY_TYPE);
        if ($template === null) {
            $this->logger->warning('Laenen verify mail template missing');
            return;
        }

        $this->dispatch($template, $customer, $salesChannel, [
            'customer' => $customer,
            'shortCode' => $shortCode,
            'confirmUrl' => $confirmUrl,
            'expiresAt' => $expiresAt,
            'candidateOrderCount' => $candidates->orderCount,
            'candidateGuestCount' => \count($candidates->guestCustomerIds),
        ], $context);
    }

    public function sendCompletion(MergeResult $result, Context $context): void
    {
        if (!$this->config->sendCompletionEmail()) {
            return;
        }

        $customer = $this->loadCustomer($result->authCustomerId, $context);
        if ($customer === null) {
            return;
        }

        $salesChannel = $this->loadSalesChannel($customer->getSalesChannelId(), $context);

        $template = $this->loadTemplate(self::COMPLETE_TYPE);
        if ($template === null) {
            return;
        }

        $this->dispatch($template, $customer, $salesChannel, [
            'customer' => $customer,
            'movedOrderCount' => $result->movedOrderCount,
            'ordersUrl' => rtrim($this->getDomain($salesChannel), '/') . '/account/order',
        ], $context);
    }

    private function dispatch(
        MailTemplateEntity $template,
        CustomerEntity $customer,
        ?SalesChannelEntity $salesChannel,
        array $templateData,
        Context $context
    ): void {
        $translation = $template->getTranslation('subject') ?? $template->getSubject();

        $data = new DataBag();
        $data->set('recipients', [$customer->getEmail() => trim(($customer->getFirstName() ?? '') . ' ' . ($customer->getLastName() ?? ''))]);
        $data->set('senderName', $template->getTranslation('senderName') ?? ($salesChannel?->getName() ?? 'Laenen'));
        $data->set('subject', $translation ?? 'Laenen notification');
        $data->set('contentHtml', $template->getTranslation('contentHtml') ?? $template->getContentHtml());
        $data->set('contentPlain', $template->getTranslation('contentPlain') ?? $template->getContentPlain());
        if ($salesChannel !== null) {
            $data->set('salesChannelId', $salesChannel->getId());
        }

        try {
            $this->mailService->send($data->all(), $context, $templateData);
        } catch (\Throwable $e) {
            $this->logger->error('Laenen mail dispatch failed', [
                'error' => $e->getMessage(),
                'template' => $template->getId(),
            ]);
        }
    }

    private function loadCustomer(string $hexId, Context $context): ?CustomerEntity
    {
        /** @var CustomerEntity|null $customer */
        $customer = $this->customerRepository->search(
            (new Criteria([$hexId]))->addAssociation('salesChannel'),
            $context
        )->first();
        return $customer;
    }

    private function loadSalesChannel(string $hexId, Context $context): ?SalesChannelEntity
    {
        /** @var SalesChannelEntity|null $sc */
        $sc = $this->salesChannelRepository->search(
            (new Criteria([$hexId]))->addAssociation('domains'),
            $context
        )->first();
        return $sc;
    }

    private function loadTemplate(string $technicalName): ?MailTemplateEntity
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->addAssociation('mailTemplateType');
        $criteria->setLimit(1);

        /** @var MailTemplateEntity|null $template */
        $template = $this->mailTemplateRepository->search($criteria, $context)->first();
        return $template;
    }

    private function buildConfirmUrl(?SalesChannelEntity $salesChannel, string $token): string
    {
        $domain = $this->getDomain($salesChannel);
        return rtrim($domain, '/') . '/account/merge-guest-orders/confirm/' . $token;
    }

    private function getDomain(?SalesChannelEntity $salesChannel): string
    {
        if ($salesChannel === null || $salesChannel->getDomains() === null) {
            return '';
        }
        $first = $salesChannel->getDomains()->first();
        return $first ? $first->getUrl() : '';
    }
}
