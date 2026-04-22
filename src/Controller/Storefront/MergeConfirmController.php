<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Controller\Storefront;

use Laenen\GuestMerge\Exception\MergeException;
use Laenen\GuestMerge\Service\GuestOrderFinder;
use Laenen\GuestMerge\Service\GuestOrderMerger;
use Laenen\GuestMerge\Service\MergeNotificationMailer;
use Laenen\GuestMerge\Service\MergeRequestService;
use Laenen\GuestMerge\Service\SystemConfigReader;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront'], '_loginRequired' => true])]
class MergeConfirmController extends StorefrontController
{
    public function __construct(
        private readonly MergeRequestService $requestService,
        private readonly GuestOrderFinder $finder,
        private readonly GuestOrderMerger $merger,
        private readonly MergeNotificationMailer $mailer,
        private readonly SystemConfigReader $config,
    ) {}

    #[Route(path: '/account/merge-guest-orders', name: 'frontend.account.laenen.merge.index', methods: ['GET'])]
    public function index(SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $candidates = $this->finder->findCandidatesFor($customer->getId(), $salesChannelContext->getSalesChannelId());
        $latest = $this->requestService->loadLatestForCustomer($customer->getId());

        return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/index.html.twig', [
            'page' => [
                'candidates' => $candidates,
                'latestRequest' => $latest,
                'allowSelfService' => $this->config->allowSelfServiceInitiation(),
            ],
        ]);
    }

    #[Route(path: '/account/merge-guest-orders/initiate', name: 'frontend.account.laenen.merge.initiate', methods: ['POST'])]
    public function initiate(SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        if (!$this->config->allowSelfServiceInitiation()) {
            $this->addFlash('warning', $this->trans('laenen.merge.error.selfServiceDisabled'));
            return $this->redirectToRoute('frontend.account.laenen.merge.index');
        }

        try {
            $result = $this->requestService->initiate(
                $customer->getId(),
                null,
                $salesChannelContext->getContext(),
                $salesChannelContext->getSalesChannelId()
            );
            $this->mailer->sendVerification(
                $customer->getId(),
                $result['token'],
                $result['shortCode'],
                $result['expiresAt'],
                $result['candidates'],
                $salesChannelContext->getContext()
            );
            $this->addFlash('success', $this->trans('laenen.merge.flash.verificationSent', [
                '%email%' => $customer->getEmail(),
            ]));
        } catch (MergeException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('frontend.account.laenen.merge.index');
    }

    #[Route(path: '/account/merge-guest-orders/confirm/{token}', name: 'frontend.account.laenen.merge.confirm', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function confirmGet(string $token, SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('frontend.account.login.page', [
                'redirectTo' => 'frontend.account.laenen.merge.confirm',
                'redirectParameters' => json_encode(['token' => $token]),
            ]);
        }

        try {
            $row = $this->requestService->loadPendingByToken($token, $customer->getId());
            $candidates = $this->finder->findCandidatesFor(
                $customer->getId(),
                $salesChannelContext->getSalesChannelId()
            );

            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/confirm.html.twig', [
                'page' => [
                    'token' => $token,
                    'request' => $row,
                    'candidates' => $candidates,
                ],
            ]);
        } catch (MergeException $e) {
            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/error.html.twig', [
                'page' => ['errorMessage' => $e->getMessage()],
            ]);
        }
    }

    #[Route(path: '/account/merge-guest-orders/confirm/{token}', name: 'frontend.account.laenen.merge.confirm.post', requirements: ['token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function confirmPost(
        string $token,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): Response {
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        try {
            $row = $this->requestService->loadPendingByToken($token, $customer->getId());
            $requestHexId = bin2hex($row['id']);

            $this->requestService->markConfirmed(
                $requestHexId,
                MergeRequestService::METHOD_LINK,
                $salesChannelContext->getContext()
            );

            $result = $this->merger->executeForRequest($requestHexId, $salesChannelContext->getContext());
            $this->mailer->sendCompletion($result, $salesChannelContext->getContext());

            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/success.html.twig', [
                'page' => ['result' => $result],
            ]);
        } catch (MergeException $e) {
            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/error.html.twig', [
                'page' => ['errorMessage' => $e->getMessage()],
            ]);
        }
    }
}
