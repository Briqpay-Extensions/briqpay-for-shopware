<?php declare(strict_types=1);

namespace Briqpay\Payments\Subscriber;

use Briqpay\Payments\Service\BriqpaySessionService;
use Shopware\Core\Checkout\Cart\Event\CartLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for enriching Store API cart responses with Briqpay session data.
 *
 * This is essential for headless integrations. It ensures that when
 * the cart is fetched via the Store API, the Briqpay session data (including the
 * HTML iframe snippet) is included in the response extensions.
 */
class StoreApiSubscriber implements EventSubscriberInterface
{
    /**
     * @param BriqpaySessionService $sessionService
     */
    public function __construct(
        private readonly BriqpaySessionService $sessionService
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CartLoadedEvent::class => 'onCartLoaded',
        ];
    }

    /**
     * Injects Briqpay session data into the loaded cart object.
     *
     * @param CartLoadedEvent $event
     */
    public function onCartLoaded(CartLoadedEvent $event): void
    {
        $this->sessionService->injectSessionData($event->getCart(), $event->getSalesChannelContext());
    }
}
