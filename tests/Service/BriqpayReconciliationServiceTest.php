<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Service;

use Briqpay\Payments\Service\BriqpayLockService;
use Briqpay\Payments\Service\BriqpayReconciliationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * @covers \Briqpay\Payments\Service\BriqpayReconciliationService
 */
class BriqpayReconciliationServiceTest extends TestCase
{
    private $transactionRepository;
    private $stateHandler;
    private $lockService;
    private $logger;
    private $service;

    protected function setUp(): void
    {
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->stateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->lockService = $this->createMock(BriqpayLockService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->lockService->method('withLock')->willReturnCallback(
            fn (string $key, int $ttl, callable $callback) => $callback()
        );

        $this->service = new BriqpayReconciliationService(
            $this->transactionRepository,
            $this->stateHandler,
            $this->lockService,
            $this->logger
        );
    }

    private function transaction(string $technicalName, ?string $sessionId): OrderTransactionEntity
    {
        $state = new StateMachineStateEntity();
        $state->setTechnicalName($technicalName);

        $transaction = new OrderTransactionEntity();
        $transaction->setId(Uuid::randomHex());
        $transaction->setStateMachineState($state);
        $transaction->setCustomFields($sessionId !== null ? ['briqpay_session_id' => $sessionId] : []);

        return $transaction;
    }

    private function searchResultWith(array $entities): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new OrderTransactionCollection($entities));

        return $result;
    }

    public function testFailsTransactionStuckOpenPastThreshold(): void
    {
        $transaction = $this->transaction('open', 'sess-stale');
        $this->transactionRepository->method('search')->willReturn($this->searchResultWith([$transaction]));

        $this->stateHandler->expects($this->once())->method('fail')->with($transaction->getId());

        $summary = $this->service->reconcileStaleOrders();

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['errors']);
    }

    public function testSkipsTransactionWithoutSessionId(): void
    {
        $transaction = $this->transaction('open', null);
        $this->transactionRepository->method('search')->willReturn($this->searchResultWith([$transaction]));

        $this->stateHandler->expects($this->never())->method('fail');

        $summary = $this->service->reconcileStaleOrders();

        $this->assertSame(0, $summary['failed']);
    }

    public function testDoesNotFailWhenLockIsHeldByAConcurrentWebhook(): void
    {
        $transaction = $this->transaction('open', 'sess-racing');
        $this->transactionRepository->method('search')->willReturn($this->searchResultWith([$transaction]));

        $this->lockService = $this->createMock(BriqpayLockService::class);
        $this->lockService->method('withLock')->willThrowException(new \RuntimeException('locked'));
        $this->service = new BriqpayReconciliationService(
            $this->transactionRepository,
            $this->stateHandler,
            $this->lockService,
            $this->logger
        );

        $this->stateHandler->expects($this->never())->method('fail');

        $summary = $this->service->reconcileStaleOrders();

        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['errors']);
    }
}
