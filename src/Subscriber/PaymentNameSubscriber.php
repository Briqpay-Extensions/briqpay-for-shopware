<?php declare(strict_types=1);

namespace Briqpay\Payments\Subscriber;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber for dynamically overriding payment method names based on Briqpay transaction data.
 *
 * Briqpay acts as a mediator for multiple sub-payment methods (Swish, Klarna, etc.).
 * To provide a better user experience, this subscriber swaps the generic "Briqpay" name
 * with the specific PSP name (e.g. "Swish") in the order history and admin views.
 */
class PaymentNameSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'order.loaded' => 'onOrderLoaded',
            'order_transaction.loaded' => 'onTransactionLoaded',
            /**
             * Critical for Admin view consistency when payment methods are loaded
             * without a specific order context initially.
             */
            'payment_method.loaded' => 'onPaymentMethodLoaded',
        ];
    }

    /**
     * Overrides the payment method name when orders are loaded.
     */
    public function onOrderLoaded(EntityLoadedEvent $event): void
    {
        foreach ($event->getEntities() as $order) {
            if (!$order instanceof OrderEntity) {
                continue;
            }

            $pspName = $order->getCustomFields()['briqpay_psp_name'] ?? null;
            $transactions = $order->getTransactions();

            if ($pspName && $transactions) {
                foreach ($transactions as $transaction) {
                    if ($transaction->getPaymentMethod()) {
                        $this->applyOverride($transaction->getPaymentMethod(), $pspName);
                    }
                }
            }
        }
    }

    /**
     * Overrides the payment method name when transactions are loaded.
     */
    public function onTransactionLoaded(EntityLoadedEvent $event): void
    {
        foreach ($event->getEntities() as $transaction) {
            if (!$transaction instanceof OrderTransactionEntity) {
                continue;
            }

            $pspName = $transaction->getCustomFields()['briqpay_psp_name'] ?? null;
            if ($pspName && $transaction->getPaymentMethod()) {
                $this->applyOverride($transaction->getPaymentMethod(), $pspName);
            }
        }
    }

    /**
     * Placeholder for generic payment method loading.
     *
     * Typically used to ensure the in-memory object has correct data if
     * loaded via an order association. Full administration consistency often
     * requires additional Vue component overrides.
     */
    public function onPaymentMethodLoaded(EntityLoadedEvent $event): void
    {
    }

    /**
     * Applies the name override to the PaymentMethod entity and its translations.
     */
    private function applyOverride(PaymentMethodEntity $method, string $pspName): void
    {
        $newName = $pspName;

        // The administration shows a payment method by its distinguishable
        // name ("<name> | <plugin>"), not its name, so both are overridden or
        // the order page keeps saying "Briqpay | Briqpay Payments" whatever
        // the shopper actually paid with.
        $distinguishable = $newName . ' | Briqpay Payments';

        $method->setName($newName);
        $method->setDistinguishableName($distinguishable);

        $translated = $method->getTranslated();
        $translated['name'] = $newName;
        $translated['distinguishableName'] = $distinguishable;
        $method->setTranslated($translated);
    }
}
