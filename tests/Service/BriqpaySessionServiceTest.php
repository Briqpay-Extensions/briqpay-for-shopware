<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Service;

use Briqpay\Payments\Components\BriqpayRequestFactory;
use Briqpay\Payments\Service\BriqpaySessionService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Covers BriqpaySessionService::getWebhookUrl()'s resolution order (fixed
 * override > discovery source > sales-channel domain fallback) — this is what
 * lets a local development tunnel deliver webhooks without any
 * separate sync process, by having the plugin poll the tunnel's own metrics
 * endpoint directly.
 *
 * @covers \Briqpay\Payments\Service\BriqpaySessionService
 *
 * buildPayload() dispatches BriqpaySessionPreCreateEvent and reads the payload
 * back off it, so these tests execute that class without being a test of it.
 * Declaring it keeps beStrictAboutCoversAnnotation on, so a test that strays
 * into some *other* part of the plugin is still reported as risky.
 *
 * @uses \Briqpay\Payments\Event\BriqpaySessionPreCreateEvent
 */
class BriqpaySessionServiceTest extends TestCase
{
    private $configService;
    private $httpClient;
    private $router;
    private $requestFactory;
    private $languageRepository;
    private $countryRepository;
    private $service;

    protected function setUp(): void
    {
        $this->configService = $this->createMock(SystemConfigService::class);
        $this->httpClient = $this->createMock(Client::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->requestFactory = $this->createMock(BriqpayRequestFactory::class);
        $this->languageRepository = $this->createMock(EntityRepository::class);
        $this->countryRepository = $this->createMock(EntityRepository::class);

        $this->requestFactory->method('mapItems')->willReturn([]);
        $this->requestFactory->method('calculateTotals')->willReturn(['amountIncVat' => 0, 'amountExVat' => 0]);
        $this->requestFactory->method('mapAddress')->willReturn([]);

        $this->router->method('generate')->willReturnCallback(
            fn (string $name) => '/' . str_replace('.', '/', $name)
        );

        $countryResult = $this->createMock(EntitySearchResult::class);
        $country = new CountryEntity();
        $country->setIso('SE');
        $countryResult->method('first')->willReturn($country);
        $this->countryRepository->method('search')->willReturn($countryResult);

        $langResult = $this->createMock(EntitySearchResult::class);
        $langResult->method('first')->willReturn(null); // falls back to sv-SE
        $this->languageRepository->method('search')->willReturn($langResult);

        $this->service = new BriqpaySessionService(
            $this->configService,
            $this->httpClient,
            $this->router,
            $this->requestFactory,
            $this->createMock(LoggerInterface::class),
            $this->languageRepository,
            $this->countryRepository,
            $this->createMock(EventDispatcherInterface::class),
            new RequestStack(),
            $this->createMock(SalesChannelContextPersister::class)
        );
    }

    private function contextWithDomain(?CustomerEntity $customer = null): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('SEK');
        $currency->setTotalRounding(new CashRoundingConfig(2, 0.01, false));

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');
        $salesChannel->setCountryId('country-1');
        $salesChannel->setDomains(new SalesChannelDomainCollection([]));

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getToken')->willReturn('sw-token');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }

    private function cart(): Cart
    {
        $cart = new Cart('cart-token');
        $cart->setLineItems(new LineItemCollection());

        return $cart;
    }

    private function customerWithBillingCountry(string $iso): CustomerEntity
    {
        $country = new CountryEntity();
        $country->setIso($iso);

        $address = new CustomerAddressEntity();
        $address->setId('addr-1');
        $address->setFirstName('Test');
        $address->setLastName('Customer');
        $address->setStreet('Test street 1');
        $address->setCity('Test city');
        $address->setZipcode('00000');
        $address->setCountry($country);

        $customer = new CustomerEntity();
        $customer->setId('cust-1');
        $customer->setAccountType('private');
        $customer->setEmail('test@example.com');
        $customer->setActiveBillingAddress($address);
        $customer->setActiveShippingAddress($address);

        return $customer;
    }

    public function testWebhookUrlUsesFixedOverrideWhenSet(): void
    {
        $this->configService->method('get')->willReturnCallback(
            fn (string $key) => $key === 'BriqpayPayments.config.webhookBaseUrl' ? 'https://fixed.example.com/' : null
        );

        $this->httpClient->expects($this->never())->method('get');

        $payload = $this->service->buildPayload($this->cart(), $this->contextWithDomain());

        $this->assertSame('https://fixed.example.com/frontend/briqpay/webhook', $payload['hooks'][0]['url']);
    }

    public function testWebhookUrlUsesDiscoverySourceWhenNoFixedOverride(): void
    {
        $this->configService->method('get')->willReturnCallback(
            fn (string $key) => $key === 'BriqpayPayments.config.webhookBaseUrlSource'
                ? 'http://tunnel-metrics:2000/status'
                : null
        );

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('http://tunnel-metrics:2000/status', $this->anything())
            ->willReturn(new Response(200, [], json_encode(['url' => 'https://tunnel.example.com'])));

        $payload = $this->service->buildPayload($this->cart(), $this->contextWithDomain());

        $this->assertSame('https://tunnel.example.com/frontend/briqpay/webhook', $payload['hooks'][0]['url']);
    }

    public function testWebhookUrlDiscoveryIsCachedAcrossCalls(): void
    {
        $this->configService->method('get')->willReturnCallback(
            fn (string $key) => $key === 'BriqpayPayments.config.webhookBaseUrlSource'
                ? 'http://tunnel-metrics:2000/status'
                : null
        );

        // Only ever called once even though buildPayload() runs twice below.
        $this->httpClient->expects($this->once())
            ->method('get')
            ->willReturn(new Response(200, [], json_encode(['url' => 'https://cached.example.com'])));

        $context = $this->contextWithDomain();
        $this->service->buildPayload($this->cart(), $context);
        $payload = $this->service->buildPayload($this->cart(), $context);

        $this->assertSame('https://cached.example.com/frontend/briqpay/webhook', $payload['hooks'][0]['url']);
    }

    public function testWebhookUrlFallsBackToSalesChannelDomainWhenNothingConfigured(): void
    {
        $this->configService->method('get')->willReturn(null);
        $this->httpClient->expects($this->never())->method('get');

        $payload = $this->service->buildPayload($this->cart(), $this->contextWithDomain());

        $this->assertSame('http://localhost/frontend/briqpay/webhook', $payload['hooks'][0]['url']);
    }

    /**
     * Regression test: a sales channel with more than one domain configured
     * (e.g. this plugin's own local-dev tunnel domain added alongside the
     * real storefront domain) must resolve browser-facing URLs (the checkout
     * redirect) against whichever domain the shopper is actually on, not an
     * arbitrary "first" domain — otherwise the post-payment redirect can send
     * the browser to a completely different domain than the one it started
     * the checkout on. Discovered live: this exact scenario broke the
     * checkout redirect during manual purchase-flow testing.
     */
    public function testRedirectUrlPrefersDomainMatchingCurrentRequestOverArbitraryFirst(): void
    {
        $this->configService->method('get')->willReturn(null);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $currency->setTotalRounding(new CashRoundingConfig(2, 0.01, false));

        $firstDomain = new SalesChannelDomainEntity();
        $firstDomain->setId('dom-1');
        $firstDomain->setUrl('http://localhost');

        $secondDomain = new SalesChannelDomainEntity();
        $secondDomain->setId('dom-2');
        $secondDomain->setUrl('http://localhost:8077');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');
        $salesChannel->setCountryId('country-1');
        // "first" domain is deliberately NOT the one the request is on.
        $salesChannel->setDomains(new SalesChannelDomainCollection([$firstDomain, $secondDomain]));

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getCustomer')->willReturn(null);
        $context->method('getToken')->willReturn('sw-token');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('http://localhost:8077/checkout/confirm'));

        $service = new BriqpaySessionService(
            $this->configService,
            $this->httpClient,
            $this->router,
            $this->requestFactory,
            $this->createMock(LoggerInterface::class),
            $this->languageRepository,
            $this->countryRepository,
            $this->createMock(EventDispatcherInterface::class),
            $requestStack,
            $this->createMock(SalesChannelContextPersister::class)
        );

        $payload = $service->buildPayload($this->cart(), $context);

        $this->assertStringStartsWith('http://localhost:8077/', $payload['urls']['redirect']);
    }

    /**
     * Regression test: a merchant selling into several markets from one
     * sales channel (one storefront, currency/country picked by the shopper)
     * needs Briqpay's top-level "country" to reflect each customer's own
     * billing country, not a single fixed sales-channel country — otherwise
     * every order looks like it's from the same market regardless of who
     * actually placed it (discovered via real purchases all showing up under
     * the sales channel's country instead of the customer's own).
     */
    public function testSessionCountryPrefersCustomerBillingCountryOverSalesChannel(): void
    {
        $context = $this->contextWithDomain($this->customerWithBillingCountry('FI'));

        $payload = $this->service->buildPayload($this->cart(), $context);

        $this->assertSame('FI', $payload['country']);
    }

    public function testSessionCountryFallsBackToSalesChannelWhenNoCustomer(): void
    {
        $context = $this->contextWithDomain(null);

        $payload = $this->service->buildPayload($this->cart(), $context);

        // contextWithDomain()'s mocked countryRepository always resolves to SE.
        $this->assertSame('SE', $payload['country']);
    }

    private function businessCustomer(?string $vatId, string $companyName = 'Test Company AB'): CustomerEntity
    {
        $customer = $this->customerWithBillingCountry('SE');
        $customer->setAccountType('business');
        $customer->getActiveBillingAddress()->setCompany($companyName);

        if ($vatId !== null) {
            $customer->setVatIds([$vatId]);
        }

        return $customer;
    }

    /**
     * Shopware's "VAT ID" is a VAT number, so it belongs in vatNo. cin is a
     * national organisation number, which Shopware does not collect.
     */
    public function testBusinessSessionSendsTheVatIdAsVatNo(): void
    {
        $payload = $this->service->buildPayload(
            $this->cart(),
            $this->contextWithDomain($this->businessCustomer('SE556677889901'))
        );

        $this->assertSame('business', $payload['customerType']);
        $this->assertSame('Test Company AB', $payload['data']['company']['name']);
        $this->assertSame('SE556677889901', $payload['data']['company']['vatNo']);
        $this->assertArrayNotHasKey('cin', $payload['data']['company']);
    }

    /**
     * Regression test: a placeholder cin of "0000000000" was sent whenever the
     * customer had no VAT id. Downstream systems treat cin as a real company
     * identifier, so a made-up one causes real problems for the merchant --
     * the company name alone is enough for a business session.
     */
    public function testBusinessSessionWithoutAVatIdSendsOnlyTheCompanyName(): void
    {
        $payload = $this->service->buildPayload(
            $this->cart(),
            $this->contextWithDomain($this->businessCustomer(null))
        );

        $this->assertSame(['name' => 'Test Company AB'], $payload['data']['company']);
    }

    public function testBusinessSessionIgnoresABlankVatId(): void
    {
        $payload = $this->service->buildPayload(
            $this->cart(),
            $this->contextWithDomain($this->businessCustomer('   '))
        );

        $this->assertArrayNotHasKey('vatNo', $payload['data']['company']);
        $this->assertArrayNotHasKey('cin', $payload['data']['company']);
    }

    public function testConsumerSessionSendsNoCompanyBlock(): void
    {
        $payload = $this->service->buildPayload(
            $this->cart(),
            $this->contextWithDomain($this->customerWithBillingCountry('SE'))
        );

        $this->assertSame('consumer', $payload['customerType']);
        $this->assertArrayNotHasKey('company', $payload['data']);
    }

    /**
     * Briqpay wants billing and shipping as objects; an empty PHP array
     * serialises to [] and is refused with "body.data.billing is the wrong
     * type", which was filling the log on guest checkouts.
     */
    public function testEmptyAddressesAreOmittedRatherThanSentAsEmptyArrays(): void
    {
        $payload = $this->service->buildPayload($this->cart(), $this->contextWithDomain());

        $this->assertArrayNotHasKey('billing', $payload['data']);
        $this->assertArrayNotHasKey('shipping', $payload['data']);
    }

    public function testTermsUrlUsesTheConfiguredOverrideWhenSet(): void
    {
        $this->configService->method('get')->willReturnCallback(
            fn (string $key) => $key === 'BriqpayPayments.config.termsUrl' ? 'https://shop.example.com/terms' : null
        );

        $payload = $this->service->buildPayload($this->cart(), $this->contextWithDomain());

        $this->assertSame('https://shop.example.com/terms', $payload['urls']['terms']);
    }

    /**
     * Without an override, the shop's own Terms of Service page (the same
     * core.basicInformation.tosPage a merchant sets up for Shopware's native
     * checkout) is used, rather than falling straight to the home page.
     */
    public function testTermsUrlFallsBackToTheShopsOwnTosPageWhenNoOverrideIsSet(): void
    {
        $this->configService->method('get')->willReturnCallback(
            fn (string $key) => $key === 'core.basicInformation.tosPage' ? 'tos-page-id' : null
        );

        // A dedicated router double: the shared one from setUp() is already
        // stubbed for every route name and, since that stub was configured
        // first, would keep answering for frontend.cms.page too rather than
        // this test's params-aware behaviour.
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            fn (string $name, array $params = []) => $name === 'frontend.cms.page'
                ? '/nav/' . ($params['id'] ?? '')
                : '/' . str_replace('.', '/', $name)
        );

        $service = new BriqpaySessionService(
            $this->configService,
            $this->httpClient,
            $router,
            $this->requestFactory,
            $this->createMock(LoggerInterface::class),
            $this->languageRepository,
            $this->countryRepository,
            $this->createMock(EventDispatcherInterface::class),
            new RequestStack(),
            $this->createMock(SalesChannelContextPersister::class)
        );

        $payload = $service->buildPayload($this->cart(), $this->contextWithDomain());

        $this->assertStringEndsWith('/nav/tos-page-id', $payload['urls']['terms']);
    }

    public function testTermsUrlFallsBackToTheHomePageWhenNothingIsConfigured(): void
    {
        $this->configService->method('get')->willReturn(null);

        $payload = $this->service->buildPayload($this->cart(), $this->contextWithDomain());

        $this->assertStringEndsWith('/frontend/home/page', $payload['urls']['terms']);
    }
}
