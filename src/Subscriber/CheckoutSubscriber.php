<?php declare(strict_types=1);

namespace Briqpay\Payments\Subscriber;

use Briqpay\Payments\Service\BriqpaySessionService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for capturing checkout-related events to synchronize Briqpay sessions.
 *
 * This subscriber is the primary orchestrator for the Briqpay session lifecycle.
 * It ensures the session data (amount, addresses, customer type) is always in sync
 * with the Shopware cart/context.
 *
 * Key Logic: syncSession() detects if a new session is mandatory (e.g. customer type switch)
 * or if an update is sufficient.
 */
class CheckoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BriqpaySessionService $sessionService,
        private readonly \Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister $contextPersister,
        private readonly \Shopware\Core\Checkout\Cart\SalesChannel\CartService $cartService
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutLoaded',
            \Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent::class => 'onContextSwitch',
            \Shopware\Core\Checkout\Cart\Event\CartChangedEvent::class => 'onCartChanged',
        ];
    }

    /**
     * Handles the initial loading of the checkout confirmation page.
     * Injects the Briqpay HTML snippet into the page extensions.
     */
    public function onCheckoutLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $cart = $page->getCart();
        $context = $event->getSalesChannelContext();

        $briqpayData = $this->syncSession($cart, $context);

        if (isset($briqpayData['htmlSnippet']) && !isset($briqpayData['snippet'])) {
            $briqpayData['snippet'] = $briqpayData['htmlSnippet'];
        }

        $page->addExtension('briqpay', new ArrayStruct($briqpayData));
    }

    /**
     * Handles context changes (e.g. address switches) during checkout.
     */
    public function onContextSwitch(\Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent $event): void
    {
        $this->handleSync($event->getSalesChannelContext());
    }

    /**
     * Handles cart modifications (e.g. quantity updates) during checkout.
     */
    public function onCartChanged(\Shopware\Core\Checkout\Cart\Event\CartChangedEvent $event): void
    {
        $this->handleSync($event->getSalesChannelContext());
    }

    /**
     * Shared logic for background session synchronization triggered by AJAX events.
     */
    private function handleSync(\Shopware\Core\System\SalesChannel\SalesChannelContext $context): void
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $this->syncSession($cart, $context);
    }

    /**
     * Synchronizes the Shopware cart/context with a Briqpay session.
     *
     * 1. If no session exists, it creates one.
     * 2. If customer type (B2C/B2B) changes, it FORCES a new session.
     * 3. If only data (amount, addresses) changes, it PATCHES the session.
     *
     * @param \Shopware\Core\Checkout\Cart\Cart                      $cart    Active Shopware cart.
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $context Active context.
     *
     * @return array Briqpay session data.
     */
    private function syncSession(\Shopware\Core\Checkout\Cart\Cart $cart, \Shopware\Core\System\SalesChannel\SalesChannelContext $context): array
    {
        // Nothing to sell, nothing to sync. CartChangedEvent also fires when an
        // order empties the cart; opening a session for that would be refused
        // ("cart has less items than allowed") and only produce log noise.
        if ($cart->getLineItems()->count() === 0) {
            return [];
        }

        $contextData = $this->contextPersister->load($context->getToken(), $context->getSalesChannelId(), $context->getCustomerId());
        $briqpaySessionId = $contextData['briqpay_session_id'] ?? null;

        try {
            if (!$briqpaySessionId) {
                return $this->updateAndSaveSession(null, $cart, $context);
            }

            $existingSession = $this->sessionService->getSession($briqpaySessionId);

            if (isset($existingSession['error']) || !isset($existingSession['sessionId'])) {
                return $this->updateAndSaveSession(null, $cart, $context);
            }

            // A completed session belongs to an order that has been placed. It
            // cannot be updated (SESSION_ALREADY_COMPLETED), so the next
            // checkout starts fresh instead of trying and failing first.
            if (($existingSession['status'] ?? '') === 'completed') {
                return $this->updateAndSaveSession(null, $cart, $context);
            }

            $newPayload = $this->sessionService->buildPayload($cart, $context);

            // Detect customer type change – requires a fresh session
            $currentType = $existingSession['customerType'] ?? 'consumer';
            $newType = $newPayload['customerType'] ?? 'consumer';
            $hasExistingCompany = !empty($existingSession['data']['company']['name'] ?? '');
            $hasNewCompany = !empty($newPayload['data']['company']['name'] ?? '');

            if ($currentType !== $newType || ($hasExistingCompany !== $hasNewCompany)) {
                return $this->updateAndSaveSession(null, $cart, $context);
            }

            // Detect data changes that require a session update
            $currentData = $existingSession['data'] ?? [];
            $newData = $newPayload['data'] ?? [];

            $currentAmount = (int) ($currentData['order']['amountIncVat'] ?? 0);
            $newAmount = (int) ($newData['order']['amountIncVat'] ?? 0);
            $hasChanges = ($currentAmount !== $newAmount);

            if (
                $this->addressesChanged($currentData['billing'] ?? [], $newData['billing'] ?? []) ||
                $this->addressesChanged($currentData['shipping'] ?? [], $newData['shipping'] ?? [])
            ) {
                $hasChanges = true;
            }

            if ($this->companyChanged($currentData['company'] ?? [], $newData['company'] ?? [])) {
                $hasChanges = true;
            }

            $currentRef = $existingSession['references']['reference2'] ?? '';
            $newRef = $newPayload['references']['reference2'] ?? '';
            if ($currentRef !== $newRef) {
                $hasChanges = true;
            }

            if ($hasChanges) {
                $briqpayData = $this->sessionService->updateSession($briqpaySessionId, $cart, $context);
                if (isset($briqpayData['error'])) {
                    // Update failed (e.g. session completed), create a new session
                    $briqpayData = $this->updateAndSaveSession(null, $cart, $context);
                }
            } else {
                $briqpayData = $existingSession;
            }

            $cart->addExtension('briqpay', new ArrayStruct($briqpayData));

            return $briqpayData;

        } catch (\Exception $e) {
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Creates a new session and persists the session ID to the context.
     */
    private function updateAndSaveSession(?string $sessionId, \Shopware\Core\Checkout\Cart\Cart $cart, \Shopware\Core\System\SalesChannel\SalesChannelContext $context): array
    {
        $briqpayData = $this->sessionService->createSession($cart, $context);

        if (isset($briqpayData['sessionId'])) {
            $this->contextPersister->save(
                $context->getToken(),
                ['briqpay_session_id' => $briqpayData['sessionId']],
                $context->getSalesChannelId(),
                $context->getCustomerId()
            );
        }

        return $briqpayData;
    }

    /**
     * Compares address fields to detect changes requiring a session update.
     */
    private function addressesChanged(array $existing, array $new): bool
    {
        $fields = ['firstName', 'lastName', 'streetAddress', 'city', 'zip', 'country', 'email', 'phoneNumber'];
        foreach ($fields as $field) {
            $existingValue = $existing[$field] ?? '';
            $newValue = $new[$field] ?? '';
            if ((string) $existingValue !== (string) $newValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compares company fields to detect changes requiring a session update (B2B).
     */
    private function companyChanged(array $existing, array $new): bool
    {
        $fields = ['name', 'cin', 'vatNo'];
        foreach ($fields as $field) {
            $existingValue = $existing[$field] ?? '';
            $newValue = $new[$field] ?? '';
            if ((string) $existingValue !== (string) $newValue) {
                return true;
            }
        }

        return false;
    }
}
