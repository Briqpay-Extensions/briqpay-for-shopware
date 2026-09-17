<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Service;

use Briqpay\Payments\Components\BriqpayRequestFactory;
use Briqpay\Payments\Service\BriqpayCaptureService;
use Briqpay\Payments\Service\BriqpayLockService;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * @covers \Briqpay\Payments\Service\BriqpayCaptureService
 */
class BriqpayCaptureServiceTest extends TestCase
{
    private $requestFactory;
    private $client;
    private $transactionRepository;
    private $connection;
    private $logger;
    private $stateHandler;
    private $orderRepository;
    private $lockService;
    private $stateMachineRegistry;
    private $service;

    /**
     * Columns assumed to already exist on `briqpay_capture` so that
     * BriqpayCaptureService::ensureColumns()/hasColumn() short-circuit
     * without attempting any ALTER TABLE statements against the mocked connection.
     */
    private const EXISTING_COLUMNS = [
        ['Field' => 'id'],
        ['Field' => 'order_transaction_id'],
        ['Field' => 'order_id'],
        ['Field' => 'briqpay_session_id'],
        ['Field' => 'briqpay_capture_id'],
        ['Field' => 'parent_capture_id'],
        ['Field' => 'amount'],
        ['Field' => 'is_final'],
        ['Field' => 'type'],
        ['Field' => 'items'],
        ['Field' => 'created_at'],
    ];

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(BriqpayRequestFactory::class);
        $this->client = $this->createMock(Client::class);
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->stateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->lockService = $this->createMock(BriqpayLockService::class);
        $this->stateMachineRegistry = $this->createMock(StateMachineRegistry::class);

        // Lock acquisition is out of scope for these tests — just run the callback.
        $this->lockService->method('withLock')->willReturnCallback(
            fn (string $key, int $ttl, callable $callback) => $callback()
        );

        // Pretend the schema is already fully migrated so ensureColumns() is a no-op.
        $this->connection->method('fetchAllAssociative')->willReturn(self::EXISTING_COLUMNS);

        $this->service = new BriqpayCaptureService(
            $this->requestFactory,
            $this->client,
            $this->transactionRepository,
            $this->connection,
            $this->logger,
            $this->stateHandler,
            $this->orderRepository,
            $this->lockService,
            $this->stateMachineRegistry
        );
    }

    private function mockTransaction(string $transactionId, string $orderId, ?string $sessionId, string $state = 'authorized'): OrderTransactionEntity
    {
        $transaction = new OrderTransactionEntity();
        $transaction->setId($transactionId);
        $transaction->setOrderId($orderId);
        $transaction->setCustomFields($sessionId !== null ? ['briqpay_session_id' => $sessionId] : []);

        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setId(Uuid::randomHex());
        $stateEntity->setTechnicalName($state);
        $transaction->setStateMachineState($stateEntity);

        $order = new OrderEntity();
        $order->setId($orderId);
        $transaction->setOrder($order);

        return $transaction;
    }

    private function searchResultReturning($entity): EntitySearchResult
    {
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($entity);

        return $searchResult;
    }

    public function testCaptureSuccess(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $items = [['id' => 'item-1', 'quantity' => 1]];

        $transaction = $this->mockTransaction($transactionId, Uuid::randomHex(), 'sess-123');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning(null));

        $this->requestFactory->method('mapCaptureItems')->willReturn($items);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 1000, 'amountExVat' => 800]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn(['Authorization' => 'Basic token']);

        $this->client->expects($this->once())
            ->method('post')
            ->with('https://api.briqpay.com/v3/session/sess-123/order/capture', $this->anything())
            ->willReturn(new Response(200, [], json_encode(['captureId' => 'cap-123'])));

        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                'briqpay_capture',
                $this->callback(function (array $data) {
                    return $data['briqpay_capture_id'] === 'cap-123'
                        && (int) $data['is_final'] === 0
                        && $data['type'] === 'capture';
                })
            );

        $captureId = $this->service->capture($transactionId, 10.00, $items, true, $context);

        $this->assertEquals('cap-123', $captureId);
    }

    public function testCaptureThrowsOnApiError(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = 'trans-err';
        $transaction = $this->mockTransaction($transactionId, 'order-err', 'sess-err');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $this->requestFactory->method('mapCaptureItems')->willReturn([]);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 0, 'amountExVat' => 0]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn([]);

        $request = new GuzzleRequest('POST', 'https://api.briqpay.com/v3/session/sess-err/order/capture');
        $this->client->method('post')->willThrowException(
            new RequestException('Bad request', $request, new Response(400, [], '{"message":"INVALID_DATA"}'))
        );

        $this->connection->expects($this->never())->method('insert');

        $this->expectException(\RuntimeException::class);
        $this->service->capture($transactionId, 10.00, [], false, $context);
    }

    public function testRefundSuccess(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $items = [['id' => 'item-1', 'quantity' => 1]];

        // A refund acts on money already captured, so the transaction is paid.
        $transaction = $this->mockTransaction($transactionId, Uuid::randomHex(), 'sess-456', 'paid');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning(null));

        $this->requestFactory->method('mapCaptureItems')->willReturn($items);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 500, 'amountExVat' => 400]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn(['Authorization' => 'Basic token']);

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'https://api.briqpay.com/v3/session/sess-456/order/refund',
                $this->callback(fn (array $opts) => ($opts['json']['captureId'] ?? null) === 'cap-parent-123')
            )
            ->willReturn(new Response(200, [], json_encode(['refundId' => 'ref-789'])));

        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                'briqpay_capture',
                $this->callback(function (array $data) {
                    return $data['briqpay_capture_id'] === 'ref-789'
                        && $data['type'] === 'refund'
                        && $data['parent_capture_id'] === 'cap-parent-123';
                })
            );

        $refundId = $this->service->refund($transactionId, 'cap-parent-123', 5.00, $items, $context);

        $this->assertEquals('ref-789', $refundId);
    }

    public function testCaptureThrowsWhenTransactionNotFound(): void
    {
        $context = Context::createDefaultContext();
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning(null));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction missing-id (or its order) not found');

        $this->service->capture('missing-id', 10.00, [], false, $context);
    }

    public function testCaptureThrowsWhenNoSessionId(): void
    {
        $context = Context::createDefaultContext();
        $transaction = $this->mockTransaction('trans-no-session', 'order-no-session', null);
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No Briqpay session ID found');

        $this->service->capture('trans-no-session', 10.00, [], false, $context);
    }

    public function testRefundThrowsWhenTransactionNotFound(): void
    {
        $context = Context::createDefaultContext();
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning(null));

        $this->expectException(\RuntimeException::class);

        $this->service->refund('missing-id', 'cap-1', 5.00, [], $context);
    }

    public function testCancelSuccess(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();

        $transaction = $this->mockTransaction($transactionId, Uuid::randomHex(), 'sess-cancel');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        // No prior captures for this order.
        $this->connection->method('fetchOne')->willReturn(0);

        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn([]);

        $this->client->expects($this->once())
            ->method('post')
            ->with('https://api.briqpay.com/v3/session/sess-cancel/order/cancel', $this->anything());

        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                'briqpay_capture',
                $this->callback(fn (array $data) => $data['type'] === 'cancel')
            );

        $this->stateHandler->expects($this->once())->method('cancel')->with($transactionId, $this->anything());

        $this->service->cancel($transactionId, $context);
    }

    public function testCancelThrowsWhenAlreadyCaptured(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();

        $transaction = $this->mockTransaction($transactionId, Uuid::randomHex(), 'sess-cancel');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        // A prior capture exists for this order — cancel must be refused.
        $this->connection->method('fetchOne')->willReturn(50.0);

        $this->client->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancel is only possible before any capture');

        $this->service->cancel($transactionId, $context);
    }

    public function testCaptureMarksPaidDespiteFloatingPointRoundingDrift(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $orderId = Uuid::randomHex();
        $items = [['id' => 'item-1', 'quantity' => 1]];

        $transaction = $this->mockTransaction($transactionId, $orderId, 'sess-drift');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setAmountTotal(100.0);
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        // Simulates the sum of several prior partial captures landing a hair
        // under the order total due to floating-point noise, even though every
        // individual capture was accepted in full by Briqpay.
        $this->connection->method('fetchOne')->willReturn(100.0 - 0.0000000001);

        $this->requestFactory->method('mapCaptureItems')->willReturn($items);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 1000, 'amountExVat' => 800]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn([]);

        $this->client->method('post')->willReturn(new Response(200, [], json_encode(['captureId' => 'cap-drift'])));

        $this->stateHandler->expects($this->once())->method('paid')->with($transactionId, $this->anything());
        $this->stateHandler->expects($this->never())->method('payPartially');

        $this->service->capture($transactionId, 10.00, $items, true, $context);
    }

    /**
     * Regression test for a real scenario hit live: splitting a single
     * capture across two partial calls (1 unit, then the remaining 2 of a
     * 3-unit line) left the sum of the captures a few cents short of
     * Shopware's own independently-computed order total, because each
     * partial capture's rounding doesn't necessarily reconstitute the
     * rounding a single one-shot capture of the whole line would have
     * produced. Briqpay's own side already reports such an order as fully
     * captured (captured_full) — Shopware must not disagree and get stuck
     * showing "partially paid" forever for an order that's actually settled.
     */
    public function testCaptureMarksPaidDespiteSmallGenuineRoundingGapAcrossPartialCaptures(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $orderId = Uuid::randomHex();
        $items = [['id' => 'item-1', 'quantity' => 2]];

        $transaction = $this->mockTransaction($transactionId, $orderId, 'sess-split');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setAmountTotal(1487.85);
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        // Mirrors the real numbers observed live: a prior 1-unit capture of
        // 495.94 plus this 2-unit capture landing the running total at
        // 1487.83 — 2 cents short of the 1487.85 order total, but within the
        // tolerance this order should still flip to "paid".
        $this->connection->method('fetchOne')->willReturn(1487.83);

        $this->requestFactory->method('mapCaptureItems')->willReturn($items);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 99189, 'amountExVat' => 79992]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn([]);

        $this->client->method('post')->willReturn(new Response(200, [], json_encode(['captureId' => 'cap-split-2'])));

        $this->stateHandler->expects($this->once())->method('paid')->with($transactionId, $this->anything());
        $this->stateHandler->expects($this->never())->method('payPartially');

        $this->service->capture($transactionId, 991.89, $items, false, $context);
    }

    /**
     * The tolerance above must stay narrow: a genuinely incomplete capture
     * (well beyond a few cents of rounding slack) must still show as
     * "partially paid", not be silently treated as done.
     */
    public function testCaptureStaysPartialWhenGapIsLargerThanRoundingTolerance(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $orderId = Uuid::randomHex();
        $items = [['id' => 'item-1', 'quantity' => 1]];

        $transaction = $this->mockTransaction($transactionId, $orderId, 'sess-real-partial');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setAmountTotal(100.0);
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        // Genuinely only half captured — 50.00 out of 100.00, nowhere near
        // the few-cent rounding tolerance.
        $this->connection->method('fetchOne')->willReturn(50.0);

        $this->requestFactory->method('mapCaptureItems')->willReturn($items);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 5000, 'amountExVat' => 4000]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn([]);

        $this->client->method('post')->willReturn(new Response(200, [], json_encode(['captureId' => 'cap-real-partial'])));

        $this->stateHandler->expects($this->once())->method('payPartially')->with($transactionId, $this->anything());
        $this->stateHandler->expects($this->never())->method('paid');

        $this->service->capture($transactionId, 50.00, $items, false, $context);
    }

    public function testCaptureThrowsWhenLockAlreadyHeld(): void
    {
        $context = Context::createDefaultContext();

        $this->lockService = $this->createMock(BriqpayLockService::class);
        $this->stateMachineRegistry = $this->createMock(StateMachineRegistry::class);
        $this->lockService->method('withLock')->willThrowException(
            new \RuntimeException('Briqpay: operation already in progress for "briqpay_capture_trans-1"')
        );

        $service = new BriqpayCaptureService(
            $this->requestFactory,
            $this->client,
            $this->transactionRepository,
            $this->connection,
            $this->logger,
            $this->stateHandler,
            $this->orderRepository,
            $this->lockService,
            $this->stateMachineRegistry
        );

        $this->client->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $service->capture('trans-1', 10.00, [], false, $context);
    }

    /**
     * Regression test for a bug confirmed live against Shopware 6.6: capturing
     * the remainder of a partially captured order was refused with
     * "Illegal transition \"paid\" from state ..." and the order stayed on
     * paid_partially while Briqpay reported captured_full. Shopware's
     * transaction state machine reaches paid from paid_partially through the
     * "pay" action; the "paid" action OrderTransactionStateHandler::paid()
     * fires only exists from authorized/open.
     */
    public function testCapturingTheRemainderUsesThePayTransitionFromPaidPartially(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $orderId = Uuid::randomHex();
        $items = [['id' => 'item-1', 'quantity' => 2]];

        $transaction = $this->mockTransaction($transactionId, $orderId, 'sess-remainder', 'paid_partially');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setAmountTotal(100.0);
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        $this->connection->method('fetchOne')->willReturn(100.0);

        $this->requestFactory->method('mapCaptureItems')->willReturn($items);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 6000, 'amountExVat' => 4800]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn([]);
        $this->client->method('post')->willReturn(new Response(200, [], json_encode(['captureId' => 'cap-remainder'])));

        // paid() would throw IllegalTransitionException here.
        $this->stateHandler->expects($this->never())->method('paid');
        $this->stateMachineRegistry->expects($this->once())
            ->method('transition')
            ->with($this->callback(
                fn (Transition $transition) => $transition->getTransitionName() === 'pay'
                    && $transition->getEntityId() === $transactionId
            ));

        $this->service->capture($transactionId, 60.00, $items, true, $context);
    }

    /**
     * Order management must wait for Briqpay's approval. Capturing an
     * authorisation that does not exist yet fails at the PSP and leaves the
     * local ledger and the transaction state disagreeing, so it is refused
     * before any API call is made.
     */
    public function testCaptureIsRefusedWhileThePaymentIsStillPending(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();

        $transaction = $this->mockTransaction($transactionId, Uuid::randomHex(), 'sess-open', 'open');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $this->client->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has not approved this payment yet');

        $this->service->capture($transactionId, 10.00, [], false, $context);
    }

    public function testCancelIsRefusedOnAnOrderHeldForManualReview(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();

        $transaction = $this->mockTransaction($transactionId, Uuid::randomHex(), 'sess-review', 'reminded');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $this->client->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has not approved this payment yet');

        $this->service->cancel($transactionId, $context);
    }

    public function testRefundIsRefusedOnAnAuthorisedButUncapturedPayment(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();

        $transaction = $this->mockTransaction($transactionId, Uuid::randomHex(), 'sess-auth', 'authorized');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $this->client->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot refund');

        $this->service->refund($transactionId, 'cap-1', 10.00, [], $context);
    }

    /**
     * A PSP that captures on authorisation takes the money without this plugin
     * asking. The capture has to appear in the ledger by itself -- previously a
     * merchant had to press Capture for an order that was already captured,
     * which would then fail or double-capture at the PSP.
     */
    public function testSyncFromSessionRecordsACaptureMadeOutsideShopware(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $orderId = Uuid::randomHex();

        $transaction = $this->mockTransaction($transactionId, $orderId, 'sess-auto', 'authorized');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setAmountTotal(100.0);
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        // Nothing recorded locally yet; the ledger total afterwards is the full amount.
        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn(100.0);

        $this->connection->expects($this->once())
            ->method('insert')
            ->with('briqpay_capture', $this->callback(
                fn (array $data) => $data['briqpay_capture_id'] === 'cap-auto'
                    && $data['type'] === 'capture'
                    && abs($data['amount'] - 100.0) < 0.001
            ));

        $this->stateHandler->expects($this->once())->method('paid');

        $this->service->syncFromSession($orderId, [
            'sessionId' => 'sess-auto',
            'data' => ['captures' => [[
                'captureId' => 'cap-auto',
                'status' => 'approved',
                'autoCaptured' => true,
                'amountIncVat' => 10000,
                'cart' => [],
            ]]],
        ], $context);
    }

    public function testSyncFromSessionSkipsCapturesItHasAlreadyRecorded(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = Uuid::randomHex();
        $orderId = Uuid::randomHex();

        $transaction = $this->mockTransaction($transactionId, $orderId, 'sess-known', 'paid');
        $this->transactionRepository->method('search')->willReturn($this->searchResultReturning($transaction));

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setAmountTotal(100.0);
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        $this->connection->method('fetchFirstColumn')->willReturn(['cap-known']);
        $this->connection->method('fetchOne')->willReturn(100.0);

        $this->connection->expects($this->never())->method('insert');

        $this->service->syncFromSession($orderId, [
            'sessionId' => 'sess-known',
            'data' => ['captures' => [[
                'captureId' => 'cap-known',
                'status' => 'approved',
                'amountIncVat' => 10000,
                'cart' => [],
            ]]],
        ], $context);
    }

    public function testSyncFromSessionIgnoresASessionWithNoCapturesOrRefunds(): void
    {
        $context = Context::createDefaultContext();

        $this->connection->expects($this->never())->method('insert');
        $this->transactionRepository->expects($this->never())->method('search');

        $this->service->syncFromSession(Uuid::randomHex(), ['data' => ['captures' => [], 'refunds' => []]], $context);
    }
}
