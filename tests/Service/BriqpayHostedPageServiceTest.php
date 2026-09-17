<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Service;

use Briqpay\Payments\Components\BriqpayRequestFactory;
use Briqpay\Payments\Service\BriqpayHostedPageService;
use Briqpay\Payments\Service\BriqpayLockService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\RouterInterface;

/**
 * @covers \Briqpay\Payments\Service\BriqpayHostedPageService
 */
class BriqpayHostedPageServiceTest extends TestCase
{
    private $requestFactory;
    private $client;
    private $orderRepository;
    private $languageRepository;
    private $configService;
    private $router;
    private $lockService;
    private $service;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(BriqpayRequestFactory::class);
        $this->client = $this->createMock(Client::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->languageRepository = $this->createMock(EntityRepository::class);
        $this->configService = $this->createMock(SystemConfigService::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->method('generate')->willReturn('/briqpay/finalize');
        $this->lockService = $this->createMock(BriqpayLockService::class);
        $this->lockService->method('withLock')->willReturnCallback(
            fn (string $key, int $ttl, callable $callback) => $callback()
        );

        $langResult = $this->createMock(EntitySearchResult::class);
        $langResult->method('first')->willReturn(null);
        $this->languageRepository->method('search')->willReturn($langResult);

        $this->requestFactory->method('mapOrderItems')->willReturn([]);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 0, 'amountExVat' => 0]);
        $this->requestFactory->method('mapOrderAddress')->willReturn([]);
        $this->requestFactory->method('getBriqpayBaseUrl')->willReturn('https://api.briqpay.com');
        $this->requestFactory->method('getApiHeader')->willReturn([]);

        $this->service = new BriqpayHostedPageService(
            $this->requestFactory,
            $this->client,
            $this->orderRepository,
            $this->languageRepository,
            $this->configService,
            $this->router,
            $this->lockService,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function orderWithTransactionState(?string $technicalName): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setOrderNumber('10042');
        $order->setLanguageId(Uuid::randomHex());

        $currency = new CurrencyEntity();
        $currency->setIsoCode('SEK');
        $order->setCurrency($currency);

        if ($technicalName !== null) {
            $state = new StateMachineStateEntity();
            $state->setTechnicalName($technicalName);

            $transaction = new OrderTransactionEntity();
            $transaction->setId(Uuid::randomHex());
            $transaction->setStateMachineState($state);

            $order->setTransactions(new OrderTransactionCollection([$transaction]));
        }

        return $order;
    }

    private function searchResultReturning($entity): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($entity);

        return $result;
    }

    public function testCreatesHostedPageForFreshOrder(): void
    {
        $order = $this->orderWithTransactionState('open');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'https://api.briqpay.com/v3/hosted-page',
                $this->callback(fn (array $opts) => ($opts['json']['modules']['config']['payment']['decision']['enabled'] ?? true) === false)
            )
            ->willReturn(new Response(200, [], json_encode(['sessionId' => 'sess-hp', 'pageUrl' => 'https://pay.briqpay.com/hp/xyz'])));

        $this->orderRepository->expects($this->once())
            ->method('update')
            ->with($this->callback(fn (array $updates) => $updates[0]['customFields']['briqpay_hosted_page_url'] === 'https://pay.briqpay.com/hp/xyz'));

        $result = $this->service->createHostedPage($order->getId(), Context::createDefaultContext());

        $this->assertSame('https://pay.briqpay.com/hp/xyz', $result['pageUrl']);
        $this->assertSame('sess-hp', $result['sessionId']);
    }

    public function testRefusesWhenOrderAlreadyPaid(): void
    {
        $order = $this->orderWithTransactionState('paid');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        $this->client->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has captured or refunded funds');

        $this->service->createHostedPage($order->getId(), Context::createDefaultContext());
    }

    public function testRefusesWhenOrderAlreadyPartiallyRefunded(): void
    {
        $order = $this->orderWithTransactionState('refunded_partially');
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        $this->client->expects($this->never())->method('post');

        $this->expectException(\RuntimeException::class);

        $this->service->createHostedPage($order->getId(), Context::createDefaultContext());
    }

    public function testAllowsWhenOrderHasNoTransactionYet(): void
    {
        $order = $this->orderWithTransactionState(null);
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning($order));

        $this->client->method('post')->willReturn(
            new Response(200, [], json_encode(['sessionId' => 'sess', 'pageUrl' => 'https://pay.briqpay.com/hp/abc']))
        );

        $result = $this->service->createHostedPage($order->getId(), Context::createDefaultContext());

        $this->assertSame('https://pay.briqpay.com/hp/abc', $result['pageUrl']);
    }

    public function testThrowsWhenOrderNotFound(): void
    {
        $this->orderRepository->method('search')->willReturn($this->searchResultReturning(null));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->service->createHostedPage(Uuid::randomHex(), Context::createDefaultContext());
    }
}
