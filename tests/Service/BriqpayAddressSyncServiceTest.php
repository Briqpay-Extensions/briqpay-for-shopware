<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Service;

use Briqpay\Payments\Service\BriqpayAddressSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @covers \Briqpay\Payments\Service\BriqpayAddressSyncService
 */
class BriqpayAddressSyncServiceTest extends TestCase
{
    private $orderRepository;
    private $orderAddressRepository;
    private $countryRepository;
    private $logger;
    private $service;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->orderAddressRepository = $this->createMock(EntityRepository::class);
        $this->countryRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new BriqpayAddressSyncService(
            $this->orderRepository,
            $this->orderAddressRepository,
            $this->countryRepository,
            $this->logger
        );
    }

    private function orderWithAddresses(string $billingAddressId, ?string $shippingAddressId): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setBillingAddressId($billingAddressId);

        if ($shippingAddressId !== null) {
            $delivery = new OrderDeliveryEntity();
            $delivery->setId(Uuid::randomHex());
            $delivery->setShippingOrderAddressId($shippingAddressId);
            $order->setDeliveries(new OrderDeliveryCollection([$delivery]));
        }

        return $order;
    }

    public function testSyncsBillingAddressFieldsBriqpayProvided(): void
    {
        $billingAddressId = Uuid::randomHex();
        $order = $this->orderWithAddresses($billingAddressId, null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $this->orderRepository->method('search')->willReturn($searchResult);

        $countryIds = $this->createMock(IdSearchResult::class);
        $countryIds->method('firstId')->willReturn('country-se');
        $this->countryRepository->method('searchIds')->willReturn($countryIds);

        $this->orderAddressRepository->expects($this->once())
            ->method('update')
            ->with($this->callback(function (array $updates) use ($billingAddressId) {
                $update = $updates[0];

                return $update['id'] === $billingAddressId
                    && $update['street'] === 'Main St 1'
                    && $update['city'] === 'Stockholm'
                    && $update['countryId'] === 'country-se';
            }));

        $session = [
            'data' => [
                'billing' => [
                    'streetAddress' => 'Main St 1',
                    'city' => 'Stockholm',
                    'zip' => '11122',
                    'country' => 'se',
                ],
            ],
        ];

        $this->service->syncFromSession($order->getId(), $session, Context::createDefaultContext());
    }

    public function testNeverBlanksFieldsBriqpayDidNotReturn(): void
    {
        $billingAddressId = Uuid::randomHex();
        $order = $this->orderWithAddresses($billingAddressId, null);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $this->orderRepository->method('search')->willReturn($searchResult);

        $this->orderAddressRepository->expects($this->once())
            ->method('update')
            ->with($this->callback(function (array $updates) {
                $update = $updates[0];

                // Only the field actually provided (city) should be present —
                // no key for firstName/lastName/street/etc that Briqpay omitted.
                return array_keys($update) === ['id', 'city'];
            }));

        $session = ['data' => ['billing' => ['city' => 'Gothenburg']]];

        $this->service->syncFromSession($order->getId(), $session, Context::createDefaultContext());
    }

    public function testSkipsEntirelyWhenSessionHasNoAddressData(): void
    {
        $this->orderRepository->expects($this->never())->method('search');
        $this->orderAddressRepository->expects($this->never())->method('update');

        $this->service->syncFromSession(Uuid::randomHex(), ['data' => []], Context::createDefaultContext());
    }

    public function testSyncsShippingAddressViaDelivery(): void
    {
        $billingAddressId = Uuid::randomHex();
        $shippingAddressId = Uuid::randomHex();
        $order = $this->orderWithAddresses($billingAddressId, $shippingAddressId);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);
        $this->orderRepository->method('search')->willReturn($searchResult);

        $this->orderAddressRepository->expects($this->once())
            ->method('update')
            ->with($this->callback(fn (array $updates) => $updates[0]['id'] === $shippingAddressId));

        $session = ['data' => ['shipping' => ['city' => 'Malmo']]];

        $this->service->syncFromSession($order->getId(), $session, Context::createDefaultContext());
    }

    public function testNeverThrowsWhenOrderRepositoryFails(): void
    {
        $this->orderRepository->method('search')->willThrowException(new \RuntimeException('DB down'));

        $this->logger->expects($this->once())->method('warning');

        // Should not throw — a failed sync must never break checkout.
        $this->service->syncFromSession(Uuid::randomHex(), ['data' => ['billing' => ['city' => 'X']]], Context::createDefaultContext());
        $this->addToAssertionCount(1);
    }
}
