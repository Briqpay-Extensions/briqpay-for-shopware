<?php declare(strict_types=1);

namespace Briqpay\Payments\Event;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

class BriqpayDecisionEvent extends Event
{
    public function __construct(
        private bool $approve,
        private readonly string $sessionId,
        private readonly Cart $cart,
        private readonly SalesChannelContext $salesChannelContext
    ) {
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function isApprove(): bool
    {
        return $this->approve;
    }

    public function setApprove(bool $approve): void
    {
        $this->approve = $approve;
    }

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }
}
