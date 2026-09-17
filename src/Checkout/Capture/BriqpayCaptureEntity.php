<?php declare(strict_types=1);

namespace Briqpay\Payments\Checkout\Capture;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class BriqpayCaptureEntity extends Entity
{
    use EntityIdTrait;

    protected string $orderTransactionId;
    protected string $briqpayCaptureId;
    protected float $amount;
    protected bool $isFinal;
    protected string $type = 'capture';
    protected ?array $items = null;
    protected ?OrderTransactionEntity $orderTransaction = null;

    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }

    public function setOrderTransactionId(string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }

    public function getBriqpayCaptureId(): string
    {
        return $this->briqpayCaptureId;
    }

    public function setBriqpayCaptureId(string $briqpayCaptureId): void
    {
        $this->briqpayCaptureId = $briqpayCaptureId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    public function setIsFinal(bool $isFinal): void
    {
        $this->isFinal = $isFinal;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getItems(): ?array
    {
        return $this->items;
    }

    public function setItems(?array $items): void
    {
        $this->items = $items;
    }

    public function getOrderTransaction(): ?OrderTransactionEntity
    {
        return $this->orderTransaction;
    }

    public function setOrderTransaction(?OrderTransactionEntity $orderTransaction): void
    {
        $this->orderTransaction = $orderTransaction;
    }
}
