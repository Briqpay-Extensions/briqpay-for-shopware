<?php declare(strict_types=1);

namespace Briqpay\Payments\Service;

use Briqpay\Payments\Components\BriqpayRequestFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\RouterInterface;

/**
 * Creates a Briqpay "hosted page" (pay-by-link) for an existing Shopware order.
 *
 * Unlike the normal checkout flow (an iframe embedded on the confirmation
 * page, created from a live Cart before the order exists), this is a
 * merchant-triggered action for an order that already exists — e.g. a manual/
 * phone order, or an order originally placed with a different payment method
 * that needs a Briqpay payment link sent to the customer afterwards. This
 * mirrors the WooCommerce integration's Hosted_Payment_Page feature, which
 * isn't present in the commercetools connector — there is no "canonical"
 * payload spec to cross-check against here, only WooCommerce's implementation.
 *
 * The decision engine is intentionally disabled for hosted pages
 * (modules.config.payment.decision.enabled = false): the order/cart is
 * already final and paid-for on the Shopware side, there is nothing left to
 * validate the way BriqpayDecisionController does for a live checkout.
 */
class BriqpayHostedPageService
{
    /**
     * Transaction states that mean money has already moved — both the
     * WooCommerce and commercetools Briqpay integrations independently refuse
     * to create a new payment attempt once that's true, since a hosted page
     * would let the customer pay again for an order that's already settled.
     */
    private const BLOCKED_STATES = ['paid', 'paid_partially', 'refunded', 'refunded_partially'];

    public function __construct(
        private readonly BriqpayRequestFactory $requestFactory,
        private readonly Client $client,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $languageRepository,
        private readonly SystemConfigService $configService,
        private readonly RouterInterface $router,
        private readonly BriqpayLockService $lockService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{sessionId: string, pageUrl: string}
     */
    public function createHostedPage(string $orderId, Context $context): array
    {
        return $this->lockService->withLock(
            'briqpay_hosted_page_' . $orderId,
            60,
            fn () => $this->createHostedPageInternal($orderId, $context)
        );
    }

    private function createHostedPageInternal(string $orderId, Context $context): array
    {
        $order = $this->loadOrder($orderId, $context);
        $this->assertHostedPageAllowed($order);

        $payload = $this->buildPayload($order, $context);

        try {
            $url = $this->requestFactory->getBriqpayBaseUrl() . '/v3/hosted-page';
            $response = $this->client->post($url, [
                'headers' => $this->requestFactory->getApiHeader(),
                'json' => $payload,
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->error('Briqpay: Hosted page API error', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
                'responseBody' => $responseBody,
            ]);

            throw new \RuntimeException('Briqpay Hosted Page API Error: ' . $e->getMessage());
        }

        $sessionId = $body['sessionId'] ?? null;
        $pageUrl = $body['pageUrl'] ?? $body['url'] ?? null;

        if (!$sessionId || !$pageUrl) {
            throw new \RuntimeException('Briqpay did not return a hosted page URL');
        }

        $this->orderRepository->update([
            [
                'id' => $orderId,
                'customFields' => [
                    'briqpay_session_id' => $sessionId,
                    'briqpay_hosted_page_url' => $pageUrl,
                ],
            ],
        ], $context);

        return ['sessionId' => $sessionId, 'pageUrl' => $pageUrl];
    }

    private function loadOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('salesChannel.domains');
        $criteria->addAssociation('transactions.stateMachineState');

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            throw new \RuntimeException(sprintf('Order %s not found', $orderId));
        }

        return $order;
    }

    private function assertHostedPageAllowed(OrderEntity $order): void
    {
        $transaction = $order->getTransactions()?->last();
        $state = $transaction?->getStateMachineState()?->getTechnicalName();

        if ($state !== null && in_array($state, self::BLOCKED_STATES, true)) {
            throw new \RuntimeException(
                'Cannot create a Briqpay payment link: this order already has captured or refunded funds.'
            );
        }
    }

    private function buildPayload(OrderEntity $order, Context $context): array
    {
        $items = $this->requestFactory->mapOrderItems($order->getLineItems() ?? [], $this->getShippingCosts($order));
        $totals = $this->requestFactory->calculateTotals($items);

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getDeliveries()?->first()?->getShippingOrderAddress();
        $email = $order->getOrderCustomer()?->getEmail();

        $localeCode = $this->getLocaleCode($order->getLanguageId(), $context);
        $merchantCountry = $billingAddress?->getCountry()?->getIso() ?? 'SE';

        return [
            'pageTitle' => sprintf('Order %s', $order->getOrderNumber() ?? $order->getId()),
            'showCart' => true,
            'product' => ['type' => 'payment', 'intent' => 'payment_one_time'],
            'customerType' => 'consumer',
            'locale' => $localeCode,
            'country' => $merchantCountry,
            'references' => [
                'reference1' => (string) $order->getOrderNumber(),
                'orderId' => $order->getId(),
            ],
            'data' => [
                'order' => [
                    'currency' => $order->getCurrency()?->getIsoCode() ?? 'SEK',
                    'amountIncVat' => $totals['amountIncVat'],
                    'amountExVat' => $totals['amountExVat'],
                    'cart' => $items,
                ],
                'billing' => $this->requestFactory->mapOrderAddress($billingAddress, $email),
                'shipping' => $this->requestFactory->mapOrderAddress($shippingAddress ?? $billingAddress, $email),
            ],
            'urls' => [
                'terms' => $this->configService->get('BriqpayPayments.config.termsUrl') ?? $this->getStorefrontUrl($order),
                'redirect' => $this->getStorefrontUrl($order) . $this->router->generate('frontend.briqpay.finalize') . '?sessionId={briqpay_session}',
            ],
            'modules' => [
                // Decision is disabled: the order already exists and is final on the
                // Shopware side, there is no cart/amount to validate against.
                'loadModules' => ['payment'],
                'config' => ['payment' => ['decision' => ['enabled' => false]]],
            ],
        ];
    }

    private function getShippingCosts(OrderEntity $order): ?CalculatedPrice
    {
        $deliveryCosts = $order->getDeliveries()?->first()?->getShippingCosts();
        if ($deliveryCosts !== null) {
            return $deliveryCosts;
        }

        try {
            // OrderEntity::$shippingCosts is a typed property that's only guaranteed
            // to be initialized when explicitly hydrated (e.g. via the API layer);
            // a Criteria-loaded entity like the one used here may not have it set.
            return $order->getShippingCosts();
        } catch (\Error $e) {
            return null;
        }
    }

    private function getLocaleCode(string $languageId, Context $context): string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        $language = $this->languageRepository->search($criteria, $context)->first();

        if ($language instanceof LanguageEntity && $language->getLocale()) {
            return $language->getLocale()->getCode();
        }

        return 'sv-SE';
    }

    private function getStorefrontUrl(OrderEntity $order): string
    {
        $domain = $order->getSalesChannel()?->getDomains()?->first();

        return $domain ? rtrim($domain->getUrl(), '/') : 'http://localhost';
    }
}
