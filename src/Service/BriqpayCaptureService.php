<?php declare(strict_types=1);

namespace Briqpay\Payments\Service;

use Briqpay\Payments\Components\BriqpayRequestFactory;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Service for executing capture and refund operations against the Briqpay V3 API.
 *
 * Stores capture/refund records in the briqpay_capture table using lightweight
 * DBAL inserts to avoid Shopware's versioned DAL context issues in admin actions.
 */
class BriqpayCaptureService
{
    /**
     * Bounded slack allowed when deciding whether the sum of several partial
     * captures/refunds counts as "the whole order" — see the comment in
     * transitionAfterCapture() for why an exact match can't be relied on.
     * A few minor units (cents) is enough to absorb realistic split-rounding
     * drift without masking a genuinely incomplete capture/refund.
     */
    private const ROUNDING_TOLERANCE_MINOR_UNITS = 5;

    public function __construct(
        private readonly BriqpayRequestFactory $requestFactory,
        private readonly Client $client,
        private readonly EntityRepository $transactionRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly EntityRepository $orderRepository,
        private readonly BriqpayLockService $lockService,
        private readonly StateMachineRegistry $stateMachineRegistry
    ) {
    }

    /**
     * Transaction states in which each admin operation makes sense. Anything
     * else is refused server-side, whatever the admin UI happened to show:
     * capturing an authorisation Briqpay has not granted yet fails at the PSP
     * and leaves the ledger and the transaction state disagreeing.
     */
    private const ALLOWED_STATES = [
        'capture' => ['authorized', 'paid_partially'],
        'refund' => ['paid', 'paid_partially', 'refunded_partially'],
        'cancel' => ['authorized'],
    ];

    /**
     * States that mean "Briqpay has not approved the order yet".
     */
    private const PENDING_STATES = ['open', 'in_progress', 'unconfirmed', 'reminded'];

    /**
     * Captures (full or partial) an authorized Briqpay order.
     *
     * Guarded by a lock keyed on the transaction so a double-click in the admin
     * UI (or two admin users acting at once) can't fire two overlapping captures.
     */
    public function capture(string $transactionId, float $amount, array $items, bool $isFinal, Context $context): string
    {
        return $this->lockService->withLock(
            'briqpay_capture_' . $transactionId,
            120,
            fn () => $this->captureInternal($transactionId, $amount, $items, $isFinal, $context)
        );
    }

    private function captureInternal(string $transactionId, float $amount, array $items, bool $isFinal, Context $context): string
    {
        $this->ensureColumns();

        $liveContext = Context::createDefaultContext();

        $transaction = $this->loadTransaction($transactionId, __FUNCTION__, $liveContext);

        $orderId = $transaction->getOrderId();
        $customFields = $transaction->getCustomFields() ?? [];
        $sessionId = $customFields['briqpay_session_id'] ?? null;

        if (!$sessionId) {
            throw new \RuntimeException('No Briqpay session ID found on transaction');
        }

        $mappedItems = $this->requestFactory->mapCaptureItems($this->alignWithSession($items, $sessionId));
        $totals = $this->requestFactory->calculateTotals($mappedItems);

        $payload = [
            'data' => [
                'order' => [
                    'amountIncVat' => $totals['amountIncVat'],
                    'amountExVat' => $totals['amountExVat'],
                    'currency' => $transaction->getOrder()?->getCurrency()?->getIsoCode() ?? 'SEK',
                    'cart' => $mappedItems,
                ],
            ],
        ];

        try {
            $url = $this->requestFactory->getBriqpayBaseUrl() . "/v3/session/$sessionId/order/capture";
            $response = $this->client->post($url, [
                'headers' => $this->requestFactory->getApiHeader(),
                'json' => $payload,
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            $captureId = $body['captureId'] ?? null;

            if (!$captureId) {
                throw new \RuntimeException('No captureId returned from Briqpay');
            }
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : 'No response body';
            $this->logger->error('Briqpay: Capture API error', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
                'responseBody' => $responseBody,
            ]);

            throw new \RuntimeException('Briqpay Capture API Error: ' . $e->getMessage());
        }

        $this->persistRecord($transactionId, $orderId, $sessionId, $captureId, $totals['amountIncVat'] / 100, false, $mappedItems, 'capture');

        // Transition payment status
        $this->transitionAfterCapture($transactionId, $orderId, $totals['amountIncVat'] / 100, $liveContext);

        return $captureId;
    }

    /**
     * Performs a refund against the Briqpay V3 API.
     * Refunds are scoped per-capture: each refund targets a specific captureId.
     *
     * Guarded by the same per-transaction lock as capture()/cancel() so overlapping
     * refund requests against the same transaction can't race each other.
     */
    public function refund(string $transactionId, string $captureId, float $amount, array $items, Context $context): string
    {
        return $this->lockService->withLock(
            'briqpay_capture_' . $transactionId,
            120,
            fn () => $this->refundInternal($transactionId, $captureId, $amount, $items, $context)
        );
    }

    private function refundInternal(string $transactionId, string $captureId, float $amount, array $items, Context $context): string
    {
        $this->ensureColumns();

        $liveContext = Context::createDefaultContext();

        $transaction = $this->loadTransaction($transactionId, __FUNCTION__, $liveContext);

        $orderId = $transaction->getOrderId();
        $customFields = $transaction->getCustomFields() ?? [];
        $sessionId = $customFields['briqpay_session_id'] ?? null;

        if (!$sessionId) {
            throw new \RuntimeException('No Briqpay session ID found on transaction');
        }

        $mappedItems = $this->requestFactory->mapCaptureItems($this->alignWithSession($items, $sessionId));
        $totals = $this->requestFactory->calculateTotals($mappedItems);

        // Briqpay refund API requires captureId at top level
        $payload = [
            'captureId' => $captureId,
            'data' => [
                'order' => [
                    'amountIncVat' => $totals['amountIncVat'],
                    'amountExVat' => $totals['amountExVat'],
                    'currency' => $transaction->getOrder()?->getCurrency()?->getIsoCode() ?? 'SEK',
                    'cart' => $mappedItems,
                ],
            ],
        ];

        try {
            $url = $this->requestFactory->getBriqpayBaseUrl() . "/v3/session/$sessionId/order/refund";
            $response = $this->client->post($url, [
                'headers' => $this->requestFactory->getApiHeader(),
                'json' => $payload,
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            $refundId = $body['refundId'] ?? null;

            if (!$refundId) {
                throw new \RuntimeException('No refundId returned from Briqpay');
            }
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : 'No response body';
            $this->logger->error('Briqpay: Refund API error', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
                'responseBody' => $responseBody,
            ]);

            throw new \RuntimeException('Briqpay Refund API Error: ' . $e->getMessage());
        }

        $this->persistRecord($transactionId, $orderId, $sessionId, $refundId, $totals['amountIncVat'] / 100, false, $mappedItems, 'refund', $captureId);

        // Transition payment status after refund
        $this->transitionAfterRefund($transactionId, $orderId, $totals['amountIncVat'] / 100, $liveContext);

        return $refundId;
    }

    /**
     * Cancels (voids) a Briqpay order before any capture has taken place.
     *
     * Mirrors the guard both the WooCommerce and commercetools Briqpay integrations
     * enforce independently: cancellation is only valid pre-capture. Once any amount
     * has been captured, a refund must be used instead.
     */
    public function cancel(string $transactionId, Context $context): void
    {
        $this->lockService->withLock(
            'briqpay_capture_' . $transactionId,
            120,
            function () use ($transactionId, $context) {
                $this->cancelInternal($transactionId, $context);

                return null;
            }
        );
    }

    private function cancelInternal(string $transactionId, Context $context): void
    {
        $this->ensureColumns();

        $liveContext = Context::createDefaultContext();

        $transaction = $this->loadTransaction($transactionId, __FUNCTION__, $liveContext);

        $orderId = $transaction->getOrderId();
        $customFields = $transaction->getCustomFields() ?? [];
        $sessionId = $customFields['briqpay_session_id'] ?? null;

        if (!$sessionId) {
            throw new \RuntimeException('No Briqpay session ID found on transaction');
        }

        if ($this->getTotalByType($orderId, 'capture') > 0) {
            throw new \RuntimeException('This order already has captures — cancel is only possible before any capture. Use refund instead.');
        }

        try {
            $url = $this->requestFactory->getBriqpayBaseUrl() . "/v3/session/$sessionId/order/cancel";
            $this->client->post($url, [
                'headers' => $this->requestFactory->getApiHeader(),
            ]);
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : 'No response body';
            $this->logger->error('Briqpay: Cancel API error', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
                'responseBody' => $responseBody,
            ]);

            throw new \RuntimeException('Briqpay Cancel API Error: ' . $e->getMessage());
        }

        $this->persistRecord($transactionId, $orderId, $sessionId, 'cancel-' . $sessionId, 0.0, false, [], 'cancel');

        try {
            $this->stateHandler->cancel($transactionId, $liveContext);
        } catch (\Throwable $e) {
            $this->logger->warning('Briqpay: Could not transition payment state after cancel', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);
        }
    }

    /**
     * Persists a capture or refund record via a lightweight DBAL insert.
     */
    private function persistRecord(
        string $transactionId,
        string $orderId,
        string $briqpaySessionId,
        string $briqpayCaptureId,
        float $amount,
        bool $isFinal,
        array $mappedItems,
        string $type,
        ?string $parentCaptureId = null
    ): void {
        try {
            $data = [
                'id' => Uuid::randomBytes(),
                'order_transaction_id' => Uuid::fromHexToBytes($transactionId),
                'briqpay_capture_id' => $briqpayCaptureId,
                'amount' => $amount,
                'is_final' => (int) $isFinal,
                'items' => json_encode($mappedItems, JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ];

            if ($this->hasColumn('order_id')) {
                $data['order_id'] = Uuid::fromHexToBytes($orderId);
            }

            if ($this->hasColumn('briqpay_session_id')) {
                $data['briqpay_session_id'] = $briqpaySessionId;
            }

            if ($this->hasColumn('type')) {
                $data['type'] = $type;
            }

            if ($parentCaptureId && $this->hasColumn('parent_capture_id')) {
                $data['parent_capture_id'] = $parentCaptureId;
            }

            $this->connection->insert('briqpay_capture', $data);
        } catch (\Throwable $e) {
            $this->logger->error('Briqpay: Could not persist record', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
                'type' => $type,
            ]);

            throw $e;
        }
    }

    /**
     * Transition order transaction state after a capture.
     */
    private function transitionAfterCapture(string $transactionId, string $orderId, float $capturedAmount, Context $context): void
    {
        try {
            $orderTotal = $this->getOrderTotal($orderId, $context);
            $totalCaptured = $this->getTotalByType($orderId, 'capture');

            // Compare in integer minor units with a small tolerance, not an exact
            // float match. Confirmed live: splitting a single capture across two
            // partial calls (e.g. 1 unit then 2 units of the same line) can leave
            // a genuine few-cent gap between the sum of the captures and
            // Shopware's own independently-computed order total — each partial
            // capture rounds its own slice of the line correctly on its own, but
            // the two roundings don't necessarily add back up to the value a
            // single one-shot rounding of the whole line would have produced.
            // Briqpay's own side already reports such an order as fully captured
            // (order status captured_full) despite the same gap, so refusing to
            // follow suit here would leave the order stuck on "partially paid"
            // forever even though nothing is actually wrong or still owed.
            $state = $this->currentState($transactionId, $context);

            if ($this->toMinorUnits($totalCaptured) >= $this->toMinorUnits($orderTotal) - self::ROUNDING_TOLERANCE_MINOR_UNITS) {
                $this->markPaid($transactionId, $state, $context);
            } elseif ($state !== 'paid_partially') {
                $this->stateHandler->payPartially($transactionId, $context);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Briqpay: Could not transition payment state after capture', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);
        }
    }

    /**
     * Transition order transaction state after a refund.
     */
    private function transitionAfterRefund(string $transactionId, string $orderId, float $refundedAmount, Context $context): void
    {
        try {
            $totalCaptured = $this->getTotalByType($orderId, 'capture');
            $totalRefunded = $this->getTotalByType($orderId, 'refund');

            // Same integer-minor-unit comparison (with the same small rounding
            // tolerance) as transitionAfterCapture() — see the comment there.
            $state = $this->currentState($transactionId, $context);

            if ($this->toMinorUnits($totalRefunded) >= $this->toMinorUnits($totalCaptured) - self::ROUNDING_TOLERANCE_MINOR_UNITS) {
                if ($state !== 'refunded') {
                    $this->stateHandler->refund($transactionId, $context);
                }
            } elseif ($state !== 'refunded_partially') {
                $this->stateHandler->refundPartially($transactionId, $context);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Briqpay: Could not transition payment state after refund', [
                'error' => $e->getMessage(),
                'transactionId' => $transactionId,
            ]);
        }
    }

    /**
     * Converts a major-unit float amount (e.g. 99.995) to an integer count of
     * minor units (e.g. cents), rounding rather than truncating, so amounts
     * that only differ by floating-point noise compare as equal.
     */
    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Replaces each item's unit price and tax rate with the ones the Briqpay
     * session already holds for that reference.
     *
     * Briqpay matches a capture or refund against the session's own cart and
     * rejects a line whose unitPrice differs from it ("Cart item ... has
     * mismatching unitPrice"). A partial capture is priced as a share of the
     * line, and that share does not always round back to the same per-unit
     * figure -- capturing 2 of 3 units of a 630.27 line gives 168.08 a unit
     * where the session says 168.07. Taking the price from the session removes
     * the disagreement entirely, and the line totals stay Shopware's.
     *
     * A reference the session does not know is passed through untouched, so
     * Briqpay reports it rather than this silently sending something else.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function alignWithSession(array $items, string $sessionId): array
    {
        $cart = [];

        try {
            $response = $this->client->get(
                $this->requestFactory->getBriqpayBaseUrl() . '/v3/session/' . $sessionId,
                ['headers' => $this->requestFactory->getApiHeader()]
            );
            $session = json_decode($response->getBody()->getContents(), true);

            foreach ($session['data']['order']['cart'] ?? [] as $line) {
                if (isset($line['reference'])) {
                    $cart[(string) $line['reference']] = $line;
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: without the session the caller's own prices are used,
            // which is what happened before this alignment existed.
            $this->logger->warning('Briqpay: Could not read the session to align capture prices', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $items;
        }

        foreach ($items as $index => $item) {
            // The caller may still carry Shopware's variant suffix
            // ("SWDEMO10005.1"); the session knows the line by the same
            // normalised reference the payload was built with.
            $reference = ($item['type'] ?? '') === 'shipping_fee'
                ? 'shipping'
                : $this->requestFactory->normaliseReference((string) ($item['reference'] ?? ''));
            $line = $cart[$reference] ?? null;

            if ($line === null) {
                continue;
            }

            if (isset($line['unitPrice'])) {
                $items[$index]['unitPrice'] = (int) $line['unitPrice'];
            }

            if (isset($line['taxRate'])) {
                $items[$index]['taxRate'] = (int) $line['taxRate'];
            }
        }

        return $items;
    }

    /**
     * Loads the transaction an admin operation acts on, and refuses the
     * operation when the payment is not in a state that allows it.
     *
     * @param string $caller __FUNCTION__ of the calling *Internal method; the
     *                       operation name is what precedes "Internal"
     */
    private function loadTransaction(string $transactionId, string $caller, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('stateMachineState');
        $transaction = $this->transactionRepository->search($criteria, $context)->first();

        if (!$transaction instanceof OrderTransactionEntity || !$transaction->getOrderId()) {
            throw new \RuntimeException(sprintf('Transaction %s (or its order) not found', $transactionId));
        }

        $this->assertOperationAllowed($transaction, str_replace('Internal', '', $caller));

        return $transaction;
    }

    private function assertOperationAllowed(OrderTransactionEntity $transaction, string $operation): void
    {
        $state = $transaction->getStateMachineState()?->getTechnicalName();
        $allowed = self::ALLOWED_STATES[$operation] ?? null;

        // No state loaded (legacy rows) or an operation this table does not
        // know: nothing to check against.
        if ($state === null || $allowed === null || \in_array($state, $allowed, true)) {
            return;
        }

        if (\in_array($state, self::PENDING_STATES, true)) {
            throw new \RuntimeException(sprintf(
                'Briqpay has not approved this payment yet (transaction state "%s"). %s becomes available once the order_status webhook reports the order as approved.',
                $state,
                ucfirst($operation)
            ));
        }

        throw new \RuntimeException(sprintf('Cannot %s a payment whose transaction is "%s".', $operation, $state));
    }

    /**
     * The transaction's current technical state, or null if it cannot be read.
     */
    private function currentState(string $transactionId, Context $context): ?string
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('stateMachineState');
        $transaction = $this->transactionRepository->search($criteria, $context)->first();

        return $transaction instanceof OrderTransactionEntity
            ? $transaction->getStateMachineState()?->getTechnicalName()
            : null;
    }

    /**
     * Moves a transaction to "paid" from wherever it currently is.
     *
     * Shopware's transaction state machine reaches "paid" from "paid_partially"
     * through the "pay" action; the "paid" action that
     * OrderTransactionStateHandler::paid() fires only exists from "authorized"
     * (and "open"). Confirmed live: capturing the remainder of a partially
     * captured order was refused with an IllegalTransitionException and the
     * order stayed "partially paid" while Briqpay reported captured_full.
     */
    private function markPaid(string $transactionId, ?string $state, Context $context): void
    {
        if ($state === 'paid') {
            return;
        }

        if ($state === 'paid_partially') {
            $this->stateMachineRegistry->transition(
                new Transition(OrderTransactionDefinition::ENTITY_NAME, $transactionId, 'pay', 'stateId'),
                $context
            );

            return;
        }

        $this->stateHandler->paid($transactionId, $context);
    }

    /**
     * Brings the local ledger in line with Briqpay's own record of a session.
     *
     * Captures and refunds can happen without this plugin asking for them: a
     * PSP that captures on authorisation, or a merchant working in the Briqpay
     * dashboard. Given a session the caller has already fetched (webhooks,
     * finalize), this records every approved capture and refund the ledger
     * does not know yet and moves the transaction state to match, so the order
     * page shows the capture and offers refund rather than a Capture button
     * for money that has already been taken.
     *
     * Safe to call repeatedly: known records are skipped and the state
     * transitions are no-ops when the state already matches.
     */
    public function syncFromSession(string $orderId, array $session, Context $context): void
    {
        $captures = $session['data']['captures'] ?? [];
        $refunds = $session['data']['refunds'] ?? [];

        if (!\is_array($captures) || !\is_array($refunds) || ($captures === [] && $refunds === [])) {
            return;
        }

        $this->ensureColumns();

        $transaction = $this->findLatestTransaction($orderId, $context);
        if (!$transaction) {
            return;
        }

        $transactionId = $transaction->getId();
        $sessionId = (string) ($session['sessionId'] ?? '');

        $known = $this->connection->fetchFirstColumn(
            'SELECT briqpay_capture_id FROM briqpay_capture WHERE order_transaction_id = :id',
            ['id' => Uuid::fromHexToBytes($transactionId)]
        );

        $newRefunds = 0;

        foreach ($captures as $capture) {
            $captureId = (string) ($capture['captureId'] ?? '');
            if ($captureId === '' || ($capture['status'] ?? 'approved') !== 'approved' || \in_array($captureId, $known, true)) {
                continue;
            }

            $this->persistRecord(
                $transactionId,
                $orderId,
                $sessionId,
                $captureId,
                ((int) ($capture['amountIncVat'] ?? 0)) / 100,
                false,
                \is_array($capture['cart'] ?? null) ? $capture['cart'] : [],
                'capture'
            );
            $known[] = $captureId;

            $this->logger->info('Briqpay: Recorded a capture made outside Shopware', [
                'orderId' => $orderId,
                'captureId' => $captureId,
                'autoCaptured' => (bool) ($capture['autoCaptured'] ?? false),
            ]);
        }

        foreach ($refunds as $refund) {
            $refundId = (string) ($refund['refundId'] ?? '');
            if ($refundId === '' || ($refund['status'] ?? 'approved') !== 'approved' || \in_array($refundId, $known, true)) {
                continue;
            }

            $this->persistRecord(
                $transactionId,
                $orderId,
                $sessionId,
                $refundId,
                ((int) ($refund['amountIncVat'] ?? 0)) / 100,
                false,
                \is_array($refund['cart'] ?? null) ? $refund['cart'] : [],
                'refund',
                $refund['parentCaptureId'] ?? $refund['captureId'] ?? null
            );
            $known[] = $refundId;
            ++$newRefunds;

            $this->logger->info('Briqpay: Recorded a refund made outside Shopware', [
                'orderId' => $orderId,
                'refundId' => $refundId,
            ]);
        }

        if ($captures !== []) {
            $this->transitionAfterCapture($transactionId, $orderId, 0.0, $context);
        }

        if ($newRefunds > 0 || $refunds !== []) {
            $this->transitionAfterRefund($transactionId, $orderId, 0.0, $context);
        }
    }

    /**
     * The technical state of a transaction, looked up by transaction id or by
     * order id (newest transaction), for the admin card to act on live data
     * rather than the order it was rendered with.
     */
    public function getTransactionState(string $id): ?string
    {
        try {
            $bytes = Uuid::fromHexToBytes(explode(':', $id)[0]);
        } catch (\Throwable) {
            return null;
        }

        $state = $this->connection->fetchOne(
            'SELECT s.technical_name
             FROM order_transaction ot
             JOIN state_machine_state s ON s.id = ot.state_id
             WHERE ot.version_id = :live AND (ot.id = :id OR ot.order_id = :id)
             ORDER BY ot.created_at DESC
             LIMIT 1',
            ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'id' => $bytes]
        );

        return \is_string($state) && $state !== '' ? $state : null;
    }

    private function findLatestTransaction(string $orderId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $transaction = $this->transactionRepository->search($criteria, $context)->first();

        return $transaction instanceof OrderTransactionEntity ? $transaction : null;
    }

    /**
     * Get the order total amount.
     */
    private function getOrderTotal(string $orderId, Context $context): float
    {
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order->getAmountTotal() : 0.0;
    }

    /**
     * Get the sum of all amounts for a given type (capture or refund) for an order.
     */
    private function getTotalByType(string $orderId, string $type): float
    {
        $orderIdBinary = Uuid::fromHexToBytes($orderId);

        // Search by order_id if available, fall back to order_transaction_id
        if ($this->hasColumn('order_id')) {
            $total = $this->connection->fetchOne(
                'SELECT COALESCE(SUM(`amount`), 0) FROM `briqpay_capture` WHERE `order_id` = :orderId AND `type` = :type',
                ['orderId' => $orderIdBinary, 'type' => $type]
            );
        } else {
            $tIds = $this->connection->fetchFirstColumn(
                'SELECT id FROM order_transaction WHERE order_id = :orderId',
                ['orderId' => $orderIdBinary]
            );
            if (empty($tIds)) {
                return 0.0;
            }

            $placeholders = [];
            $params = ['type' => $type];
            foreach ($tIds as $i => $tId) {
                $p = 'tid' . $i;
                $placeholders[] = ':' . $p;
                $params[$p] = $tId;
            }
            $inClause = implode(',', $placeholders);
            $total = $this->connection->fetchOne(
                "SELECT COALESCE(SUM(`amount`), 0) FROM `briqpay_capture` WHERE `order_transaction_id` IN ($inClause) AND `type` = :type",
                $params
            );
        }

        return (float) $total;
    }

    public function getRecords(string $id): array
    {
        try {
            $this->ensureColumns();

            try {
                $hexId = explode(':', $id)[0];
                $bytes = Uuid::fromHexToBytes($hexId);
            } catch (\Exception $e) {
                return [];
            }

            // 1. Collect all possible identifiers for this order/transaction using lean SQL
            $ids = [$bytes];
            $sessionId = null;
            $orderIdBinary = null;

            // Try to find if the ID is a transaction or an order
            $row = $this->connection->fetchAssociative(
                'SELECT order_id, custom_fields FROM order_transaction WHERE id = :id',
                ['id' => $bytes]
            );

            if ($row) {
                $orderIdBinary = $row['order_id'];
                $customFields = json_decode($row['custom_fields'] ?? '{}', true);
                $sessionId = $customFields['briqpay_session_id'] ?? null;
            } else {
                // Check if it's an order ID
                $exists = $this->connection->fetchOne('SELECT 1 FROM `order` WHERE id = :id', ['id' => $bytes]);
                if ($exists) {
                    $orderIdBinary = $bytes;
                }
            }

            if ($orderIdBinary) {
                if (!in_array($orderIdBinary, $ids, true)) {
                    $ids[] = $orderIdBinary;
                }

                // Find ALL transaction IDs for this order via SQL
                $tIds = $this->connection->fetchFirstColumn(
                    'SELECT id FROM order_transaction WHERE order_id = :orderId',
                    ['orderId' => $orderIdBinary]
                );
                foreach ($tIds as $tId) {
                    if (!in_array($tId, $ids, true)) {
                        $ids[] = $tId;
                    }
                }

                // Also try to get the session ID from transactions if we don't have it yet
                if (!$sessionId) {
                    $sessionIds = $this->connection->fetchFirstColumn(
                        'SELECT custom_fields FROM order_transaction WHERE order_id = :orderId AND custom_fields IS NOT NULL',
                        ['orderId' => $orderIdBinary]
                    );
                    foreach ($sessionIds as $cfJson) {
                        $cf = json_decode($cfJson, true);
                        if (isset($cf['briqpay_session_id'])) {
                            $sessionId = $cf['briqpay_session_id'];
                            break;
                        }
                    }
                }
            }

            $idPlaceholders = [];
            $queryParams = [];
            foreach ($ids as $i => $idBinary) {
                $p = 'id' . $i;
                $idPlaceholders[] = ':' . $p;
                $queryParams[$p] = $idBinary;
            }
            $idList = implode(',', $idPlaceholders);

            $where = ['`order_transaction_id` IN (' . $idList . ')'];
            if ($this->hasColumn('order_id')) {
                $where[] = '`order_id` IN (' . $idList . ')';
            }

            if ($sessionId && $this->hasColumn('briqpay_session_id')) {
                $where[] = '`briqpay_session_id` = :sessionId';
                $queryParams['sessionId'] = $sessionId;
            }

            $sql = 'SELECT * FROM `briqpay_capture` WHERE (' . implode(' OR ', $where) . ') ORDER BY `created_at` DESC';
            $results = $this->connection->fetchAllAssociative($sql, $queryParams);

            return array_map(function (array $row) {
                $record = [
                    'id' => Uuid::fromBytesToHex($row['id'] ?? ''),
                    'orderTransactionId' => Uuid::fromBytesToHex($row['order_transaction_id'] ?? ''),
                    'orderId' => isset($row['order_id']) ? Uuid::fromBytesToHex($row['order_id']) : null,
                    'briqpaySessionId' => $row['briqpay_session_id'] ?? null,
                    'briqpayCaptureId' => $row['briqpay_capture_id'] ?? '',
                    'parentCaptureId' => $row['parent_capture_id'] ?? null,
                    'amount' => (float) ($row['amount'] ?? 0),
                    'isFinal' => (bool) ($row['is_final'] ?? false),
                    'type' => $row['type'] ?? 'capture',
                    'createdAt' => $row['created_at'] ?? '',
                ];

                $items = $row['items'] ?? '[]';
                $record['items'] = is_string($items) ? json_decode($items, true) : $items;

                return $record;
            }, $results);
        } catch (\Throwable $e) {
            $this->logger->error('Briqpay: getRecords failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return [];
        }
    }

    private function ensureColumns(): void
    {
        if (!$this->hasColumn('order_id')) {
            try {
                $this->connection->executeStatement(
                    'ALTER TABLE `briqpay_capture` ADD COLUMN `order_id` BINARY(16) NULL AFTER `order_transaction_id`'
                );
                $this->columnCache = null;
            } catch (\Exception $e) {
            }
        }

        if (!$this->hasColumn('briqpay_session_id')) {
            try {
                $this->connection->executeStatement(
                    'ALTER TABLE `briqpay_capture` ADD COLUMN `briqpay_session_id` VARCHAR(255) NULL AFTER `order_id`'
                );
                $this->columnCache = null;
            } catch (\Exception $e) {
            }
        }

        if (!$this->hasColumn('parent_capture_id')) {
            try {
                $this->connection->executeStatement(
                    'ALTER TABLE `briqpay_capture` ADD COLUMN `parent_capture_id` VARCHAR(255) NULL AFTER `briqpay_capture_id`'
                );
                $this->columnCache = null;
            } catch (\Exception $e) {
            }
        }

        if (!$this->hasColumn('type')) {
            try {
                $this->connection->executeStatement(
                    'ALTER TABLE `briqpay_capture` ADD COLUMN `type` VARCHAR(255) NULL AFTER `is_final`'
                );
                $this->columnCache = null;
            } catch (\Exception $e) {
            }
        }
    }

    private ?array $columnCache = null;

    private function hasColumn(string $columnName): bool
    {
        if ($this->columnCache === null) {
            try {
                $columns = $this->connection->fetchAllAssociative('SHOW COLUMNS FROM `briqpay_capture`');
                $this->columnCache = array_column($columns, 'Field');
            } catch (\Exception $e) {
                return false;
            }
        }

        return in_array($columnName, $this->columnCache, true);
    }
}
