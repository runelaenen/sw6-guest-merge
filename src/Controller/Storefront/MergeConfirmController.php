<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Controller\Storefront;

use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\AbstractGuestMergeRoute;
use Laenen\GuestMerge\Exception\MergeException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront'], '_loginRequired' => true])]
class MergeConfirmController extends StorefrontController
{
    public function __construct(
        private readonly AbstractGuestMergeRoute $route,
    ) {}

    #[Route(path: '/account/merge-guest-orders', name: 'frontend.account.laenen.merge.index', methods: ['GET'])]
    public function index(SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $response = $this->route->status($salesChannelContext, $customer);

        return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/index.html.twig', [
            'page' => [
                'candidates' => $response->getCandidates(),
                'latestRequest' => $response->getLatestRequest(),
                'allowSelfService' => $response->isAllowSelfService(),
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

        try {
            $this->route->initiate($salesChannelContext, $customer);
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
            $response = $this->route->confirmPreview($token, $salesChannelContext, $customer);

            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/confirm.html.twig', [
                'page' => [
                    'token' => $response->getToken(),
                    'request' => $response->getRequest(),
                    'candidates' => $response->getCandidates(),
                ],
            ]);
        } catch (MergeException $e) {
            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/error.html.twig', [
                'page' => ['errorMessage' => $e->getMessage()],
            ]);
        }
    }

    #[Route(path: '/account/merge-guest-orders/confirm/{token}', name: 'frontend.account.laenen.merge.confirm.post', requirements: ['token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function confirmPost(string $token, SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();
        if ($customer === null) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        try {
            $response = $this->route->confirmExecute($token, $salesChannelContext, $customer);

            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/success.html.twig', [
                'page' => ['result' => $response->getResult()],
            ]);
        } catch (MergeException $e) {
            return $this->renderStorefront('@LaenenGuestMerge/storefront/page/account/merge/error.html.twig', [
                'page' => ['errorMessage' => $e->getMessage()],
            ]);
        }
    }
}
