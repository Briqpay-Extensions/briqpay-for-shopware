<?php declare(strict_types=1);

namespace Briqpay\Payments\Payment;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payment handler for Briqpay integration in Shopware 6.6.
 *
 * This class handles the traditional Shopware payment flow.
 * In this specific integration, it acts mostly as a fallback or a placeholder,
 * as the primary checkout experience is handled via the Briqpay iframe embedded
 * on the checkout confirmation page.
 */
class BriqpayPaymentHandler extends AbstractPaymentHandler
{
    /**
     * @param OrderTransactionStateHandler $transactionStateHandler
     */
    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler
    ) {
    }

    /**
     * Determines if this handler supports the given payment type.
     *
     * For Shopware 6.6 redirect-based payments, we return false here
     * as PaymentHandlerType currently only covers RECURRING and REFUND.
     */
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    /**
     * Executes the payment request.
     *
     * Redirects the user to the Briqpay iframe handling route.
     *
     * @param Request                  $request
     * @param PaymentTransactionStruct $transaction
     * @param Context                  $context
     * @param Struct|null              $validateStruct
     *
     * @return RedirectResponse|null
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $transactionId = $transaction->getOrderTransactionId();

        return new RedirectResponse('/briqpay/iframe?transactionId=' . $transactionId);
    }

    /**
     * Finalizes the payment after the user returns from the redirect.
     *
     * @param Request                  $request
     * @param PaymentTransactionStruct $transaction
     * @param Context                  $context
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $this->transactionStateHandler->paid($transaction->getOrderTransactionId(), $context);
    }
}
