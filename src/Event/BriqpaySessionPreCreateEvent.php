<?php declare(strict_types=1);

namespace Briqpay\Payments\Event;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

class BriqpaySessionPreCreateEvent extends Event
{
    public function __construct(
        private array $payload,
        private readonly Cart $cart,
        private readonly SalesChannelContext $salesChannelContext
    ) {
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): \Shopware\Core\Framework\Context
    {
        return $this->salesChannelContext->getContext();
    }
}
