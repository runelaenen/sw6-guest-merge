<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Core\SalesChannel\GuestMerge;

use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeConfirmPreviewResponse;
use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeExecuteResponse;
use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeInitiateResponse;
use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\Response\GuestMergeStatusResponse;
use Laenen\GuestMerge\Exception\SelfServiceDisabledException;
use Laenen\GuestMerge\Service\GuestOrderFinder;
use Laenen\GuestMerge\Service\GuestOrderMerger;
use Laenen\GuestMerge\Service\MergeNotificationMailer;
use Laenen\GuestMerge\Service\MergeRequestService;
use Laenen\GuestMerge\Service\SystemConfigReader;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class GuestMergeRoute extends AbstractGuestMergeRoute
{
    public function __construct(
        private readonly MergeRequestService $requestService,
        private readonly GuestOrderFinder $finder,
        private readonly GuestOrderMerger $merger,
        private readonly MergeNotificationMailer $mailer,
        private readonly SystemConfigReader $config,
    ) {}

    public function getDecorated(): static
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/account/laenen/merge-guest-orders', name: 'store-api.account.laenen.merge.status', defaults: ['_loginRequired' => true], methods: ['GET'])]
    public function status(SalesChannelContext $context, CustomerEntity $customer): GuestMergeStatusResponse
    {
        $candidates = $this->finder->findCandidatesFor($customer->getId(), $context->getSalesChannelId());
        $latest = $this->requestService->loadLatestForCustomer($customer->getId());

        return new GuestMergeStatusResponse(
            $candidates,
            $latest !== null ? $this->serializeRequest($latest) : null,
            $this->config->allowSelfServiceInitiation()
        );
    }

    #[Route(path: '/store-api/account/laenen/merge-guest-orders/initiate', name: 'store-api.account.laenen.merge.initiate', defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function initiate(SalesChannelContext $context, CustomerEntity $customer): GuestMergeInitiateResponse
    {
        if (!$this->config->allowSelfServiceInitiation()) {
            throw new SelfServiceDisabledException();
        }

        $result = $this->requestService->initiate(
            $customer->getId(),
            null,
            $context->getContext(),
            $context->getSalesChannelId()
        );

        $this->mailer->sendVerification(
            $customer->getId(),
            $result['token'],
            $result['shortCode'],
            $result['expiresAt'],
            $result['candidates'],
            $context->getContext()
        );

        return new GuestMergeInitiateResponse(
            $result['id'],
            $result['expiresAt'],
            $result['candidates']->orderCount,
            \count($result['candidates']->guestCustomerIds)
        );
    }

    #[Route(path: '/store-api/account/laenen/merge-guest-orders/confirm/{token}', name: 'store-api.account.laenen.merge.confirm.preview', requirements: ['token' => '[a-f0-9]{64}'], defaults: ['_loginRequired' => true], methods: ['GET'])]
    public function confirmPreview(string $token, SalesChannelContext $context, CustomerEntity $customer): GuestMergeConfirmPreviewResponse
    {
        $row = $this->requestService->loadPendingByToken($token, $customer->getId());
        $candidates = $this->finder->findCandidatesFor($customer->getId(), $context->getSalesChannelId());

        return new GuestMergeConfirmPreviewResponse($token, $this->serializeRequest($row), $candidates);
    }

    #[Route(path: '/store-api/account/laenen/merge-guest-orders/confirm/{token}', name: 'store-api.account.laenen.merge.confirm.execute', requirements: ['token' => '[a-f0-9]{64}'], defaults: ['_loginRequired' => true], methods: ['POST'])]
    public function confirmExecute(string $token, SalesChannelContext $context, CustomerEntity $customer): GuestMergeExecuteResponse
    {
        $row = $this->requestService->loadPendingByToken($token, $customer->getId());
        $requestHexId = bin2hex($row['id']);

        $this->requestService->markConfirmed(
            $requestHexId,
            MergeRequestService::METHOD_LINK,
            $context->getContext()
        );

        $result = $this->merger->executeForRequest($requestHexId, $context->getContext());
        $this->mailer->sendCompletion($result, $context->getContext());

        return new GuestMergeExecuteResponse($result);
    }

    private function serializeRequest(array $row): array
    {
        return [
            'id' => bin2hex($row['id']),
            'status' => $row['status'],
            'verificationMethod' => $row['verification_method'],
            'candidateCount' => (int) $row['candidate_count'],
            'orderCountSnapshot' => (int) $row['order_count_snapshot'],
            'movedOrderCount' => $row['moved_order_count'] !== null ? (int) $row['moved_order_count'] : null,
            'expiresAt' => $row['expires_at'],
            'confirmedAt' => $row['confirmed_at'],
            'completedAt' => $row['completed_at'],
            'createdAt' => $row['created_at'],
            'errorMessage' => $row['error_message'],
        ];
    }
}
