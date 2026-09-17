<?php declare(strict_types=1);

namespace Briqpay\Payments\Service;

use Briqpay\Payments\Payment\BriqpayPaymentHandler;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

/**
 * Periodic reconciliation for Briqpay order transactions that never received a
 * follow-up webhook.
 *
 * Scope is deliberately conservative: a Shopware order/transaction is only
 * ever created once a checkout session has already reached order_pending or
 * order_approved_not_captured (see BriqpayController::finalize()/
 * createOrderFromWebhook()), so the transaction sits in the payment state
 * machine's initial 'open' state until a further order_status/capture_status
 * webhook moves it on. If that webhook is lost (network blip, a webhook
 * delivery that arrived before the order even existed, etc.) the transaction
 * can be stuck in 'open' forever with no local signal anything is wrong.
 *
 * We do NOT attempt to re-derive "was it actually paid/approved" from a
 * re-fetched session here, unlike WooCommerce's equivalent janitor — Briqpay's
 * session GET response schema for a definitive top-level order outcome isn't
 * something this integration has verified end-to-end against a live account,
 * and guessing at unfamiliar field names in a background task that mutates
 * order state is exactly the kind of thing that should not be guessed at.
 * Instead, this only does the one thing that's unambiguous regardless of
 * schema: transactions that are still 'open' — never even authorized — after
 * a long timeout are almost certainly abandoned checkouts, and are failed so
 * they don't sit in limbo indefinitely. Anything already past 'open'
 * (authorized/reminded/paid/etc.) is left entirely alone.
 */
class BriqpayReconciliationService
{
    private const STALE_AFTER_HOURS = 5;

    public function __construct(
        private readonly EntityRepository $transactionRepository,
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly BriqpayLockService $lockService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{checked: int, failed: int, errors: int}
     */
    public function reconcileStaleOrders(): array
    {
        $context = Context::createDefaultContext();
        $summary = ['checked' => 0, 'failed' => 0, 'errors' => 0];

        foreach ($this->findStaleTransactions($context) as $transaction) {
            $summary['checked']++;

            try {
                if ($this->failIfStillStale($transaction, $context)) {
                    $summary['failed']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                $this->logger->warning('Briqpay: Reconciliation failed for transaction', [
                    'transactionId' => $transaction->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($summary['checked'] > 0) {
            $this->logger->info('Briqpay: Reconciliation run complete', $summary);
        }

        return $summary;
    }

    /**
     * @return iterable<OrderTransactionEntity>
     */
    private function findStaleTransactions(Context $context): iterable
    {
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d hours', self::STALE_AFTER_HOURS));

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('paymentMethod.handlerIdentifier', BriqpayPaymentHandler::class));
        $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', 'open'));
        $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::LT => $threshold->format(\DATE_ATOM)]));
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order');
        $criteria->setLimit(100);

        foreach ($this->transactionRepository->search($criteria, $context)->getEntities() as $transaction) {
            if ($transaction instanceof OrderTransactionEntity) {
                yield $transaction;
            }
        }
    }

    /**
     * Re-checks a single stale transaction under a lock (so it can never race
     * a webhook that arrives for the same session while this runs) and fails
     * it if it's genuinely still stuck in 'open'.
     */
    private function failIfStillStale(OrderTransactionEntity $transaction, Context $context): bool
    {
        $customFields = $transaction->getCustomFields() ?? [];
        $sessionId = $customFields['briqpay_session_id'] ?? null;

        if (!$sessionId) {
            return false;
        }

        try {
            return (bool) $this->lockService->withLock('briqpay_session_' . $sessionId, 30, function () use ($transaction, $context) {
                // Re-read the current state inside the lock in case a webhook just moved it.
                $currentState = $transaction->getStateMachineState()?->getTechnicalName();
                if ($currentState !== 'open') {
                    return false;
                }

                $this->stateHandler->fail($transaction->getId(), $context);
                $this->logger->info('Briqpay: Failed a transaction stuck in "open" past the stale threshold', [
                    'transactionId' => $transaction->getId(),
                    'staleAfterHours' => self::STALE_AFTER_HOURS,
                ]);

                return true;
            });
        } catch (\RuntimeException $e) {
            // A webhook is actively processing this exact session right now — leave it
            // alone, the next reconciliation run will re-check it if still needed.
            return false;
        }
    }
}
