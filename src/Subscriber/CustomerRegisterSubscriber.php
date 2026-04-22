<?php declare(strict_types=1);

namespace Laenen\GuestMerge\Subscriber;

use Laenen\GuestMerge\Service\GuestOrderFinder;
use Laenen\GuestMerge\Service\SystemConfigReader;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Scenario B from the requirements thread:
 *
 *   "Customer creates an authenticated account with an email that has been used
 *    for guest orders. We do NOT auto-merge orders on account creation because
 *    we have not proven inbox ownership."
 *
 * What this subscriber does instead: when registration happens, if there are
 * guest orders matching the new account's email, we add a flash message
 * pointing the user to the self-service merge page (which DOES verify inbox
 * ownership via email link). Pure information / discoverability — no data
 * change, no auto-merge.
 */
class CustomerRegisterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly GuestOrderFinder $finder,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly SystemConfigReader $config,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerRegisterEvent::class => 'onRegister',
        ];
    }

    public function onRegister(CustomerRegisterEvent $event): void
    {
        if (!$this->config->showRegistrationHint() || !$this->config->allowSelfServiceInitiation()) {
            return;
        }

        $customer = $event->getCustomer();
        $candidates = $this->finder->findCandidatesFor(
            $customer->getId(),
            $event->getSalesChannelId()
        );

        if ($candidates->isEmpty()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (!method_exists($session, 'getFlashBag')) {
            return;
        }

        $message = $this->translator->trans('laenen.merge.flash.guestOrdersDetected', [
            '%count%' => $candidates->orderCount,
        ]);

        $session->getFlashBag()->add('info', $message);
    }
}
