<?php declare(strict_types=1);

namespace Briqpay\Payments\Service;

use Briqpay\Payments\Components\BriqpayRequestFactory;
use Briqpay\Payments\Event\BriqpayDecisionEvent;
use Briqpay\Payments\Event\BriqpaySessionPreCreateEvent;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Service for managing Briqpay checkout sessions via API.
 *
 * This core service encapsulates all Briqpay API interactions for session management.
 * It handles session creation, updates, data mapping, and custom redirection for headless clients.
 * Ensure any modifications to the payload structure are reflected in the buildPayload method.
 */
class BriqpaySessionService
{
    public function __construct(
        private readonly SystemConfigService $configService,
        private readonly Client $httpClient,
        private readonly RouterInterface $router,
        private readonly BriqpayRequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $languageRepository,
        private readonly EntityRepository $countryRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly \Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister $contextPersister
    ) {
    }

    /**
     * Initializes a new checkout session with the Briqpay API.
     *
     * @param Cart                $cart    Current shopping cart.
     * @param SalesChannelContext $context Active sales channel context.
     *
     * @return array Briqpay session response or error array.
     */
    public function createSession(Cart $cart, SalesChannelContext $context): array
    {
        $payload = $this->buildPayload($cart, $context);

        try {
            $url = $this->requestFactory->getBriqpayBaseUrl() . '/v3/session';
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'headers' => $this->requestFactory->getApiHeader(),
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : 'No response body';
            $this->logger->error('Briqpay: Could not create session', [
                'error' => $e->getMessage(),
                'responseBody' => $responseBody,
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('Briqpay: Could not create session', ['error' => $e->getMessage()]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Updates an existing Briqpay checkout session with new cart or address data.
     *
     * The Briqpay V3 PATCH endpoint only accepts 'order', 'billing', and 'shipping'
     * inside the 'data' object. Sending 'company' on a consumer session or any other
     * additional properties causes INVALID_DATA errors.
     *
     * @param string              $sessionId Existing Briqpay session identifier.
     * @param Cart                $cart      Updated cart.
     * @param SalesChannelContext $context   Active context.
     *
     * @return array Briqpay update response.
     */
    public function updateSession(string $sessionId, Cart $cart, SalesChannelContext $context): array
    {
        $fullPayload = $this->buildPayload($cart, $context);

        $data = $fullPayload['data'] ?? [];

        // Only send the fields that the Briqpay V3 PATCH endpoint accepts.
        // 'company' must NOT be sent for consumer sessions (causes INVALID_DATA).
        $updatePayload = [
            'data' => [
                'order' => $data['order'] ?? [],
            ],
        ];

        // Same reason as in buildPayload(): never send an empty address.
        foreach (['billing', 'shipping'] as $key) {
            if (!empty($data[$key])) {
                $updatePayload['data'][$key] = $data[$key];
            }
        }

        // Only include company data for business sessions
        $customerType = $fullPayload['customerType'] ?? 'consumer';
        if ($customerType === 'business' && isset($data['company'])) {
            $updatePayload['data']['company'] = $data['company'];
        }

        try {
            $url = $this->requestFactory->getBriqpayBaseUrl() . '/v3/session/' . $sessionId;
            $response = $this->httpClient->patch($url, [
                'json' => $updatePayload,
                'headers' => $this->requestFactory->getApiHeader(),
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : 'No response body';
            $this->logger->error('Briqpay: Could not update session', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
                'responseBody' => $responseBody,
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('Briqpay: Could not update session', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Constructs the data payload required for Briqpay session creation/updates.
     *
     * Central mapping logic for B2B vs B2C, localized URLs, and webhooks.
     * Dispatches BriqpaySessionPreCreateEvent to allow for custom payload injections.
     *
     * @param Cart                $cart
     * @param SalesChannelContext $context
     *
     * @return array Comprehensive API payload.
     */
    public function buildPayload(Cart $cart, SalesChannelContext $context): array
    {
        $this->requestFactory->assertSupportedCurrencyPrecision($context);

        $cartItems = $this->requestFactory->mapItems($cart->getLineItems(), $cart->getShippingCosts());
        $totals = $this->requestFactory->calculateTotals($cartItems);

        $customer = $context->getCustomer();
        $billingAddress = $customer?->getActiveBillingAddress();
        $shippingAddress = $customer?->getActiveShippingAddress();

        $companyName = $billingAddress?->getCompany();
        $isBusiness = ($customer?->getAccountType() === 'business' || !empty($companyName));
        $customerType = $isBusiness ? 'business' : 'consumer';

        $localeCode = $this->getLocaleCode($context);
        $merchantCountry = $this->getTransactionCountry($billingAddress, $context);

        $payload = [
            'product' => ['type' => 'payment', 'intent' => 'payment_one_time'],
            'customerType' => $customerType,
            'locale' => $localeCode,
            'country' => $merchantCountry,
            'references' => [
                'reference2' => $context->getToken() . '|' . $cart->getToken(),
            ],
            'hooks' => [
                [
                    'eventType' => 'order_status',
                    'statuses' => ['order_pending', 'order_approved_not_captured', 'order_rejected', 'order_cancelled'],
                    'method' => 'POST',
                    'url' => $this->getWebhookUrl($context),
                ],
                [
                    'eventType' => 'capture_status',
                    'statuses' => ['pending', 'approved', 'rejected'],
                    'method' => 'POST',
                    'url' => $this->getWebhookUrl($context),
                ],
                [
                    'eventType' => 'refund_status',
                    'statuses' => ['pending', 'approved', 'rejected'],
                    'method' => 'POST',
                    'url' => $this->getWebhookUrl($context),
                ],
            ],
            'data' => [
                'order' => [
                    'currency' => $context->getCurrency()->getIsoCode(),
                    'amountIncVat' => $totals['amountIncVat'],
                    'amountExVat' => $totals['amountExVat'],
                    'cart' => $cartItems,
                ],
            ],
            'urls' => [
                'terms' => $this->configService->get('BriqpayPayments.config.termsUrl') ?? $this->getAbsoluteUrl('frontend.home.page', $context),
                'redirect' => $this->getRedirectUrl($context),
            ],
            'modules' => [
                'loadModules' => ['payment'],
                'config' => ['payment' => ['decision' => ['enabled' => true]]],
            ],
        ];

        // Briqpay wants billing and shipping as objects. An empty PHP array is
        // serialised as [] and refused ("body.data.billing is the wrong type"),
        // so the keys are left out until there is an address to send.
        foreach (['billing' => $billingAddress, 'shipping' => $shippingAddress] as $key => $address) {
            $mapped = $this->requestFactory->mapAddress($address, $customer?->getEmail());
            if ($mapped !== []) {
                $payload['data'][$key] = $mapped;
            }
        }

        if ($isBusiness && !empty($companyName)) {
            $company = ['name' => $companyName];

            // Shopware's "VAT ID" is a VAT number, so it is sent as vatNo. cin is
            // a national organisation number, which Shopware does not collect --
            // it is never sent, and never as a placeholder: a made-up cin would
            // be treated as a real identifier by the merchant's downstream
            // systems. Company name alone is enough for a business session.
            $vatNo = trim((string) ($customer?->getVatIds()[0] ?? ''));
            if ($vatNo !== '') {
                $company['vatNo'] = $vatNo;
            }

            $payload['data']['company'] = $company;
        }

        $event = new BriqpaySessionPreCreateEvent($payload, $cart, $context);
        $this->eventDispatcher->dispatch($event);

        return $event->getPayload();
    }

    /**
     * Resolves the ISO country Briqpay should treat this transaction as being
     * conducted in.
     *
     * Prefers the customer's own billing address country — for a merchant
     * selling into several markets from a single sales channel (one storefront,
     * currency/country picked by the shopper), this is what actually varies
     * per order and determines which local payment methods Briqpay shows
     * (e.g. Swish for Sweden, Finnish bank transfers for Finland). Falls back
     * to the sales channel's own configured country only when there's no
     * billing address yet (e.g. a session created before checkout has an
     * address, or a sales channel that's genuinely single-market).
     * BriqpayHostedPageService already does the equivalent for its own,
     * always-has-an-address flow — this keeps both services consistent.
     */
    private function getTransactionCountry(?CustomerAddressEntity $billingAddress, SalesChannelContext $context): string
    {
        $billingCountryIso = $billingAddress?->getCountry()?->getIso();
        if ($billingCountryIso) {
            return $billingCountryIso;
        }

        $countryId = $context->getSalesChannel()->getCountryId();
        $criteria = new Criteria([$countryId]);

        /** @var CountryEntity|null $country */
        $country = $this->countryRepository->search($criteria, $context->getContext())->first();

        return $country ? $country->getIso() : 'SE';
    }

    /**
     * Resolves the locale code for the current language context.
     */
    private function getLocaleCode(SalesChannelContext $context): string
    {
        $criteria = new Criteria([$context->getContext()->getLanguageId()]);
        $criteria->addAssociation('locale');

        $language = $this->languageRepository->search($criteria, $context->getContext())->first();

        if ($language instanceof LanguageEntity && $language->getLocale()) {
            return $language->getLocale()->getCode();
        }

        return 'sv-SE';
    }

    /**
     * Retrieves an active checkout session directly from Briqpay.
     *
     * @param string $sessionId Briqpay session identifier.
     *
     * @return array Briqpay session data.
     */
    public function getSession(string $sessionId): array
    {
        try {
            $url = $this->requestFactory->getBriqpayBaseUrl() . "/v3/session/{$sessionId}";
            $response = $this->httpClient->get($url, [
                'headers' => $this->requestFactory->getApiHeader(),
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->logger->error('Briqpay: Could not fetch session', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Injects existing Briqpay session data into the Cart for headless/API consumption.
     */
    public function injectSessionData(Cart $cart, SalesChannelContext $context): void
    {
        if ($cart->hasExtension('briqpay')) {
            return;
        }

        $contextData = $this->contextPersister->load($context->getToken(), $context->getSalesChannelId(), $context->getCustomerId());
        $briqpaySessionId = $contextData['briqpay_session_id'] ?? null;

        if ($briqpaySessionId) {
            try {
                $session = $this->getSession($briqpaySessionId);
                if (!isset($session['error'])) {
                    $cart->addExtension('briqpay', new \Shopware\Core\Framework\Struct\ArrayStruct($session));
                }
            } catch (\Exception $e) {
                $this->logger->debug('Briqpay: Session injection failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Updates external session references (e.g. OrderNumber) in Briqpay.
     */
    public function updateReferences(string $sessionId, array $newReferences): void
    {
        if (empty($sessionId)) {
            return;
        }

        try {
            $currentSession = $this->getSession($sessionId);
            $existingReferences = $currentSession['references'] ?? [];
            $mergedReferences = array_merge($existingReferences, $newReferences);

            $url = $this->requestFactory->getBriqpayBaseUrl() . "/v3/session/{$sessionId}/order/update/references";

            $this->httpClient->patch($url, [
                'headers' => $this->requestFactory->getApiHeader(),
                'json' => ['references' => $mergedReferences],
            ]);
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : 'No response body';
            $this->logger->error('Briqpay: Error updating references', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
                'responseBody' => $responseBody,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Briqpay: Error in updateReferences', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Submits the final allow/reject decision for a given session.
     *
     * Final step of the decision validation flow.
     * Dispatches BriqpayDecisionEvent to allow external subscribers to override the status.
     */
    public function postDecision(string $sessionId, Cart $cart, SalesChannelContext $context, bool $approve): void
    {
        $event = new BriqpayDecisionEvent($approve, $sessionId, $cart, $context);
        $this->eventDispatcher->dispatch($event);

        if ($approve === true && $event->isApprove() === false) {
            $this->logger->info('Briqpay: Decision was overridden to REJECT by a subscriber');
        }

        $finalApprove = $event->isApprove();

        $decisionData = ['decision' => $finalApprove ? 'allow' : 'reject'];
        if (!$finalApprove) {
            $decisionData['rejectionType'] = 'notify_user';
        }

        $url = $this->requestFactory->getBriqpayBaseUrl() . "/v3/session/{$sessionId}/decision";

        try {
            $this->httpClient->post($url, [
                'headers' => $this->requestFactory->getApiHeader(),
                'json' => $decisionData,
            ]);
        } catch (RequestException $e) {
            $this->logger->error('Briqpay: Error posting decision', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolves the callback URL for Briqpay, supporting custom headless redirectUrl.
     */
    private function getRedirectUrl(SalesChannelContext $context): string
    {
        $redirectUrl = $this->getAbsoluteUrl('frontend.briqpay.finalize', $context) . '?sessionId={briqpay_session}';

        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $customRedirect = $request->get('redirectUrl') ?? $request->query->get('redirectUrl');
            if ($customRedirect) {
                $redirectUrl .= '&redirectUrl=' . urlencode((string) $customRedirect);
            }
        }

        return $redirectUrl;
    }

    /**
     * Helper to generate a fully qualified URL for the sales channel domain.
     */
    private function getAbsoluteUrl(string $routeName, SalesChannelContext $context): string
    {
        $baseUrl = $this->resolveCurrentDomainUrl($context) ?? $this->firstDomainUrl($context);

        return $baseUrl . $this->router->generate($routeName);
    }

    /**
     * Prefers the sales channel domain matching the current request's own
     * scheme+host over an arbitrary "first" domain. A sales channel with more
     * than one domain configured (a secondary staging domain, a webhook-only
     * domain such as this plugin's own local dev tunnel, multiple
     * language-specific domains, etc.) has no guaranteed ordering from
     * getDomains()->first() — blindly using it can send the shopper's browser
     * to a completely different domain than the one they're actually
     * checking out on. Falls back to first() when there's no active request
     * (e.g. a CLI/queue context) or its host isn't a known domain.
     */
    private function resolveCurrentDomainUrl(SalesChannelContext $context): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return null;
        }

        $currentHost = $request->getSchemeAndHttpHost();

        foreach ($context->getSalesChannel()->getDomains() ?? [] as $domain) {
            if (rtrim($domain->getUrl(), '/') === rtrim($currentHost, '/')) {
                return rtrim($domain->getUrl(), '/');
            }
        }

        return null;
    }

    private function firstDomainUrl(SalesChannelContext $context): string
    {
        $domain = $context->getSalesChannel()->getDomains()->first();

        return $domain ? rtrim($domain->getUrl(), '/') : 'http://localhost';
    }

    /** @var array<string, array{url: string, expires: int}> */
    private array $webhookUrlDiscoveryCache = [];

    /**
     * Resolves the base URL Briqpay should POST webhooks to.
     *
     * Only the webhook URL needs to be reachable from the public internet — the
     * storefront itself can stay on localhost, since only Briqpay's servers (not
     * the shopper's browser) ever call this URL. Resolution order mirrors the
     * Litium integration's CallbackHost:
     *   1. A fixed override (BriqpayPayments.config.webhookBaseUrl), e.g. a
     *      permanent ngrok/cloudflared domain or a staging URL.
     *   2. A discovery source (BriqpayPayments.config.webhookBaseUrlSource) —
     *      a URL polled (and cached for 60s) for JSON like
     *      {"url": "https://example.com"} or {"hostname": "example.com"} —
     *      which matches the metrics endpoint a local tunnel exposes, so a
     *      hostname that changes on restart needs no extra sync process.
     *   3. Fallback: the normal sales channel domain, same as every other URL.
     */
    private function getWebhookUrl(SalesChannelContext $context): string
    {
        $path = $this->router->generate('frontend.briqpay.webhook');

        $fixed = trim((string) ($this->configService->get('BriqpayPayments.config.webhookBaseUrl') ?? ''));
        if ($fixed !== '') {
            return rtrim($fixed, '/') . $path;
        }

        $source = trim((string) ($this->configService->get('BriqpayPayments.config.webhookBaseUrlSource') ?? ''));
        if ($source !== '') {
            $discovered = $this->discoverWebhookBaseUrl($source);
            if ($discovered !== null) {
                return $discovered . $path;
            }
        }

        return $this->getAbsoluteUrl('frontend.briqpay.webhook', $context);
    }

    /**
     * Polls a discovery source URL for the currently active tunnel hostname,
     * caching the result briefly so this doesn't add a network round-trip to
     * every single checkout event.
     */
    private function discoverWebhookBaseUrl(string $source): ?string
    {
        $cached = $this->webhookUrlDiscoveryCache[$source] ?? null;
        if ($cached && $cached['expires'] > time()) {
            return $cached['url'];
        }

        try {
            $response = $this->httpClient->get($source, ['timeout' => 2]);
            $data = json_decode($response->getBody()->getContents(), true);
            $hostname = $data['url'] ?? $data['hostname'] ?? null;

            if (!$hostname) {
                return $cached['url'] ?? null;
            }

            $url = str_starts_with($hostname, 'http') ? $hostname : 'https://' . $hostname;
            $url = rtrim($url, '/');

            $this->webhookUrlDiscoveryCache[$source] = ['url' => $url, 'expires' => time() + 60];

            return $url;
        } catch (\Exception $e) {
            $this->logger->debug('Briqpay: webhook URL discovery source unreachable', [
                'source' => $source,
                'error' => $e->getMessage(),
            ]);

            // Fall back to the last known-good discovered URL, if any, rather than
            // immediately dropping back to localhost on a transient blip.
            return $cached['url'] ?? null;
        }
    }
}
