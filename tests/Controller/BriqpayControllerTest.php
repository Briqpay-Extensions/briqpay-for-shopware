<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Controller;

use Briqpay\Payments\Controller\BriqpayController;
use Briqpay\Payments\Service\BriqpayAddressSyncService;
use Briqpay\Payments\Service\BriqpayCaptureService;
use Briqpay\Payments\Service\BriqpayLockService;
use Briqpay\Payments\Service\BriqpaySessionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the webhook() security/robustness behavior added on top of the
 * original implementation: duplicate-delivery dedupe, refusing to act on a
 * session the Briqpay API itself won't confirm, manual-review holds, and
 * requiring capture/refund IDs to actually appear in the verified session
 * before a capture_status/refund_status webhook is trusted.
 *
 * @covers \Briqpay\Payments\Controller\BriqpayController
 */
class BriqpayControllerTest extends TestCase
{
    private $cartService;
    private $briqpayService;
    private $orderRepository;
    private $customerRepository;
    private $stateHandler;
    private $contextFactory;
    private $logger;
    private $transactionRepository;
    private $systemConfigService;
    private $contextPersister;
    private $lockService;
    private $addressSyncService;
    private $paymentMethodRepository;
    private $captureService;
    private $controller;

    protected function setUp(): void
    {
        $this->cartService = $this->createMock(CartService::class);
        $this->briqpayService = $this->createMock(BriqpaySessionService::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->customerRepository = $this->createMock(EntityRepository::class);
        $this->stateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->contextPersister = $this->createMock(SalesChannelContextPersister::class);
        $this->lockService = $this->createMock(BriqpayLockService::class);
        $this->addressSyncService = $this->createMock(BriqpayAddressSyncService::class);
        $this->paymentMethodRepository = $this->createMock(EntityRepository::class);
        $this->captureService = $this->createMock(BriqpayCaptureService::class);

        // Dedupe claim succeeds by default; individual tests override to simulate a duplicate.
        $this->lockService->method('claimOnce')->willReturn(true);
        $this->lockService->method('withLock')->willReturnCallback(
            fn (string $key, int $ttl, callable $callback) => $callback()
        );

        $this->controller = new BriqpayController(
            $this->cartService,
            $this->briqpayService,
            $this->orderRepository,
            $this->customerRepository,
            $this->stateHandler,
            $this->contextFactory,
            $this->logger,
            $this->transactionRepository,
            $this->systemConfigService,
            $this->contextPersister,
            $this->lockService,
            $this->addressSyncService,
            $this->paymentMethodRepository,
            $this->captureService
        );
    }

    private function jsonRequest(array $payload): Request
    {
        return new Request([], [], [], [], [], [], json_encode($payload));
    }

    private function orderWithTransactionState(string $technicalName): OrderEntity
    {
        $state = new StateMachineStateEntity();
        $state->setTechnicalName($technicalName);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('11111111111111111111111111111111');
        $transaction->setStateMachineState($state);

        $order = new OrderEntity();
        $order->setId('22222222222222222222222222222222');
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        return $order;
    }

    private function searchResultReturning($entity): EntitySearchResult
    {
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($entity);

        return $searchResult;
    }

    public function testWebhookIgnoresDuplicateDelivery(): void
    {
        $this->lockService = $this->createMock(BriqpayLockService::class);
        $this->lockService->method('claimOnce')->willReturn(false);
        $this->controller = new BriqpayController(
            $this->cartService,
            $this->briqpayService,
            $this->orderRepository,
            $this->customerRepository,
            $this->stateHandler,
            $this->contextFactory,
            $this->logger,
            $this->transactionRepository,
            $this->systemConfigService,
            $this->contextPersister,
            $this->lockService,
            $this->addressSyncService,
            $this->paymentMethodRepository,
            $this->captureService
        );

        $this->briqpayService->expects($this->never())->method('getSession');

        $request = $this->jsonRequest(['sessionId' => '00000000-0000-4000-8000-000000000001', 'event' => 'order_status', 'status' => 'order_pending']);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    /**
     * A session id that isn't even shaped like one is rejected before any
     * outbound call to Briqpay -- the endpoint has no signature to check, so
     * this is the cheapest available guard against pointless traffic forcing
     * a live API lookup for every request.
     */
    public function testWebhookRejectsANonUuidSessionIdWithoutCallingBriqpay(): void
    {
        $this->briqpayService->expects($this->never())->method('getSession');

        $request = $this->jsonRequest(['sessionId' => 'not-a-real-session-id', 'event' => 'order_status', 'status' => 'order_pending']);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testWebhookReturns502WhenSessionCannotBeVerified(): void
    {
        $this->briqpayService->method('getSession')->willReturn(['error' => true, 'message' => 'not found']);

        $request = $this->jsonRequest(['sessionId' => '00000000-0000-4000-8000-000000000002', 'event' => 'order_status', 'status' => 'order_approved_not_captured']);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(502, $response->getStatusCode());
        $this->stateHandler->expects($this->never())->method('authorize');
    }

    public function testWebhookHoldsForManualReview(): void
    {
        $order = $this->orderWithTransactionState('open');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));
        $this->briqpayService->method('getSession')->willReturn([
            'sessionId' => '00000000-0000-4000-8000-000000000003',
            'data' => ['paymentTags' => ['manual_review']],
        ]);

        $this->stateHandler->expects($this->once())->method('remind')->with('11111111111111111111111111111111');
        $this->stateHandler->expects($this->never())->method('authorize');

        $request = $this->jsonRequest(['sessionId' => '00000000-0000-4000-8000-000000000003', 'event' => 'order_status', 'status' => 'order_approved_not_captured']);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testWebhookIgnoresCaptureStatusWhenCaptureIdNotInVerifiedSession(): void
    {
        $order = $this->orderWithTransactionState('authorized');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));
        $this->briqpayService->method('getSession')->willReturn([
            'sessionId' => '00000000-0000-4000-8000-000000000004',
            'data' => ['captures' => [['captureId' => 'cap-real', 'status' => 'approved']]],
        ]);

        $this->stateHandler->expects($this->never())->method('paid');

        $request = $this->jsonRequest([
            'sessionId' => '00000000-0000-4000-8000-000000000004',
            'event' => 'capture_status',
            'status' => 'approved',
            'captureId' => 'cap-fabricated',
        ]);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testWebhookAppliesCaptureStatusWhenCaptureIdVerified(): void
    {
        $order = $this->orderWithTransactionState('authorized');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));
        $this->briqpayService->method('getSession')->willReturn([
            'sessionId' => '00000000-0000-4000-8000-000000000005',
            'data' => ['captures' => [['captureId' => 'cap-real', 'status' => 'approved']]],
        ]);

        // The capture is applied through the ledger rather than by transitioning
        // straight to paid: the capture may have been made outside Shopware
        // (auto capture, Briqpay dashboard), in which case it has to be recorded
        // as well, and a partial capture must land on paid_partially instead.
        $this->captureService->expects($this->once())
            ->method('syncFromSession')
            ->with(
                '22222222222222222222222222222222',
                $this->callback(fn (array $session) => ($session['sessionId'] ?? null) === '00000000-0000-4000-8000-000000000005'),
                $this->anything()
            );

        $request = $this->jsonRequest([
            'sessionId' => '00000000-0000-4000-8000-000000000005',
            'event' => 'capture_status',
            'status' => 'approved',
            'captureId' => 'cap-real',
        ]);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * An approved order whose PSP captured on authorisation must come out of
     * the webhook both authorised and with the capture recorded, so the order
     * page shows it as paid with a capture rather than offering a Capture
     * button for money that has already been taken.
     */
    public function testWebhookRecordsAnAutoCaptureOnOrderApproval(): void
    {
        $order = $this->orderWithTransactionState('open');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));
        $this->briqpayService->method('getSession')->willReturn([
            'sessionId' => '00000000-0000-4000-8000-0000000000a0',
            'data' => ['captures' => [['captureId' => 'cap-auto', 'status' => 'approved', 'autoCaptured' => true]]],
        ]);

        $this->stateHandler->expects($this->once())->method('authorize');
        $this->captureService->expects($this->once())->method('syncFromSession');

        $request = $this->jsonRequest([
            'sessionId' => '00000000-0000-4000-8000-0000000000a0',
            'event' => 'order_status',
            'status' => 'order_approved_not_captured',
        ]);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * A transaction that is already paid (the PSP captured before the approval
     * webhook arrived) must not be pulled back to authorized.
     */
    public function testWebhookDoesNotAuthorizeAnAlreadyPaidTransaction(): void
    {
        $order = $this->orderWithTransactionState('paid');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));
        $this->briqpayService->method('getSession')->willReturn(['sessionId' => '00000000-0000-4000-8000-0000000000ad', 'data' => []]);

        $this->stateHandler->expects($this->never())->method('authorize');

        $request = $this->jsonRequest([
            'sessionId' => '00000000-0000-4000-8000-0000000000ad',
            'event' => 'order_status',
            'status' => 'order_approved_not_captured',
        ]);
        $response = $this->controller->webhook($request, $this->createMock(SalesChannelContext::class));

        $this->assertEquals(200, $response->getStatusCode());
    }
}
