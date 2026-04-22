<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Controller\Admin;

use Laenen\GuestMerge\Exception\MergeException;
use Laenen\GuestMerge\Service\GuestOrderFinder;
use Laenen\GuestMerge\Service\GuestOrderMerger;
use Laenen\GuestMerge\Service\MergeNotificationMailer;
use Laenen\GuestMerge\Service\MergeRequestService;
use Laenen\GuestMerge\Service\SystemConfigReader;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['customer.editor']])]
readonly class MergeAdminController
{
    public function __construct(
        private GuestOrderFinder $finder,
        private MergeRequestService $requestService,
        private GuestOrderMerger $merger,
        private MergeNotificationMailer $mailer,
        private SystemConfigReader $config,
    ) {}

    #[Route(path: '/api/_action/laenen/guest-merge/preview/{customerId}', name: 'api.action.laenen.guest_merge.preview', methods: ['GET'])]
    public function preview(string $customerId, Context $context): JsonResponse
    {
        $candidates = $this->finder->findCandidatesFor($customerId);
        $latest = $this->requestService->loadLatestForCustomer($customerId);

        return new JsonResponse([
            'candidates' => $candidates->toArray(),
            'latestRequest' => $latest ? $this->serializeRequest($latest) : null,
            'capabilities' => [
                'allowDirectMergeForTrustedCsr' => $this->config->allowDirectMergeForTrustedCsr(),
                'tokenLifetimeHours' => $this->config->tokenLifetimeHours(),
            ],
        ]);
    }

    #[Route(path: '/api/_action/laenen/guest-merge/initiate/{customerId}', name: 'api.action.laenen.guest_merge.initiate', methods: ['POST'])]
    public function initiate(string $customerId, Context $context): JsonResponse
    {
        $adminUserId = $this->getAdminUserId($context);

        try {
            $result = $this->requestService->initiate($customerId, $adminUserId, $context);
        } catch (MergeException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'code' => $e->getErrorCode()], $e->getStatusCode());
        }

        $this->mailer->sendVerification(
            $customerId,
            $result['token'],
            $result['shortCode'],
            $result['expiresAt'],
            $result['candidates'],
            $context
        );

        return new JsonResponse([
            'requestId' => $result['id'],
            'expiresAt' => $result['expiresAt']->format(\DateTimeInterface::ATOM),
            'candidateOrderCount' => $result['candidates']->orderCount,
            'candidateGuestCount' => \count($result['candidates']->guestCustomerIds),
            // shortCode intentionally NOT returned here. The CSR sees it in the
            // mail template render in Shopware's mail log if they have access,
            // but it must reach the customer via email - not via the CSR's screen.
            // See "Verify by code" endpoint below: the CSR enters the code the
            // customer reads back, never the other direction.
        ]);
    }

    /**
     * Customer reads the code they received over the phone; CSR enters it here
     * to confirm and immediately execute the merge.
     */
    #[Route(path: '/api/_action/laenen/guest-merge/verify-code/{customerId}', name: 'api.action.laenen.guest_merge.verify_code', methods: ['POST'])]
    public function verifyCode(string $customerId, Request $request, Context $context): JsonResponse
    {
        $code = (string)$request->request->get('code', '');
        if ($code === '') {
            return new JsonResponse(['error' => 'Missing "code" field.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $row = $this->requestService->loadPendingByShortCode($code, $customerId);
            $requestHexId = bin2hex($row['id']);
            $this->requestService->markConfirmed(
                $requestHexId,
                MergeRequestService::METHOD_VERBAL_CODE,
                $context
            );
            $result = $this->merger->executeForRequest($requestHexId, $context);
            $this->mailer->sendCompletion($result, $context);

            return new JsonResponse(['result' => $result->toArray()]);
        } catch (MergeException $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        }
    }

    /**
     * Cancel any pending request for the customer (no merge happens).
     */
    #[Route(path: '/api/_action/laenen/guest-merge/cancel/{customerId}', name: 'api.action.laenen.guest_merge.cancel', methods: ['POST'])]
    public function cancel(string $customerId): JsonResponse
    {
        $latest = $this->requestService->loadLatestForCustomer($customerId);
        if ($latest && $latest['status'] === MergeRequestService::STATUS_PENDING) {
            $this->requestService->cancel(bin2hex($latest['id']));
        }
        return new JsonResponse(['ok' => true]);
    }

    /**
     * Trusted-CSR direct merge (well-known customer, no email verification).
     * Strictly gated by config + ACL; emits an audit row with method=trusted_csr.
     */
    #[Route(path: '/api/_action/laenen/guest-merge/direct-merge/{customerId}', name: 'api.action.laenen.guest_merge.direct_merge', defaults: ['_acl' => ['laenen_guest_merge.trusted']], methods: ['POST'])]
    public function directMerge(string $customerId, Context $context): JsonResponse
    {
        if (!$this->config->allowDirectMergeForTrustedCsr()) {
            return new JsonResponse([
                'error' => 'Direct merge for trusted CSRs is disabled in plugin configuration.',
            ], Response::HTTP_FORBIDDEN);
        }

        $adminUserId = $this->getAdminUserId($context);
        if ($adminUserId === null) {
            return new JsonResponse(['error' => 'Admin user context required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->merger->executeDirectForTrustedCsr($customerId, $adminUserId, $context);
            $this->mailer->sendCompletion($result, $context);
            return new JsonResponse(['result' => $result->toArray()]);
        } catch (MergeException $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getStatusCode());
        }
    }

    private function getAdminUserId(Context $context): ?string
    {
        $source = $context->getSource();
        return $source instanceof AdminApiSource ? $source->getUserId() : null;
    }

    private function serializeRequest(array $row): array
    {
        return [
            'id' => bin2hex($row['id']),
            'status' => $row['status'],
            'verificationMethod' => $row['verification_method'],
            'candidateCount' => (int)$row['candidate_count'],
            'orderCountSnapshot' => (int)$row['order_count_snapshot'],
            'movedOrderCount' => $row['moved_order_count'] !== null ? (int)$row['moved_order_count'] : null,
            'expiresAt' => $row['expires_at'],
            'confirmedAt' => $row['confirmed_at'],
            'completedAt' => $row['completed_at'],
            'createdAt' => $row['created_at'],
            'errorMessage' => $row['error_message'],
        ];
    }
}
