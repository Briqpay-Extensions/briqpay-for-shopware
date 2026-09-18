<?php declare(strict_types=1);

namespace Briqpay\Payments\Controller;

use Briqpay\Payments\Payment\BriqpayPaymentHandler;
use Briqpay\Payments\Service\BriqpayAddressSyncService;
use Briqpay\Payments\Service\BriqpayCaptureService;
use Briqpay\Payments\Service\BriqpayLockService;
use Briqpay\Payments\Service\BriqpaySessionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for handling Briqpay payment redirects and asynchronous webhooks.
 *
 * Key Flows:
 * - finalize: Synchronous return from the iframe, converting cart to order.
 * - webhook: Asynchronous status updates and order creation as a fallback.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BriqpayController extends StorefrontController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly BriqpaySessionService $briqpayService,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $customerRepository,
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $transactionRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly SalesChannelContextPersister $contextPersister,
        private readonly BriqpayLockService $lockService,
        private readonly BriqpayAddressSyncService $addressSyncService,
        private readonly EntityRepository $paymentMethodRepository,
        private readonly BriqpayCaptureService $captureService
    ) {
    }

    /**
     * Finalizes the payment process after an external redirect back to Shopware.
     *
     * Converts the active Cart into a Shopware Order. If 'redirectUrl' is present
     * in the query, handles the response for headless clients by redirecting
     * back to the custom frontend instead of the finish page.
     */
    #[Route('/briqpay/finalize', name: 'frontend.briqpay.finalize', methods: ['GET'])]
    public function finalize(Request $request, SalesChannelContext $context): Response
    {
        $sessionId = (string) $request->query->get('sessionId');

        // Shares a lock with webhook()'s fallback order-creation path so this
        // synchronous redirect and an in-flight async webhook for the same
        // session can never both try to create the order.
        try {
            return $this->lockService->withLock(
                'briqpay_session_' . $sessionId,
                30,
                fn () => $this->finalizeInternal($request, $context, $sessionId)
            );
        } catch (\RuntimeException $e) {
            // The webhook is mid-flight for this session right now — give it a brief
            // moment to finish, then just follow the normal "already exists" path.
            usleep(300000);
            $order = $this->getOrderBySessionId($sessionId, Context::createDefaultContext());
            if ($order) {
                return $this->handleSuccessRedirect($request, $order->getId());
            }
            $this->logger->warning('Briqpay: finalize() could not acquire session lock and no order appeared', ['sessionId' => $sessionId]);

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }
    }

    private function finalizeInternal(Request $request, SalesChannelContext $context, string $sessionId): Response
    {
        // Check if the order was already created (e.g. by the webhook)
        $order = $this->getOrderBySessionId($sessionId, Context::createDefaultContext());
        if ($order) {
            $this->logger->info('Briqpay: Order already exists for session, redirecting to finish', [
                'sessionId' => $sessionId,
                'orderId' => $order->getId(),
            ]);

            return $this->handleSuccessRedirect($request, $order->getId());
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ($cart->getLineItems()->count() === 0) {
            $this->logger->warning('Briqpay: Empty cart on finalize', ['sessionId' => $sessionId]);

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        try {
            $orderId = $this->cartService->order($cart, $context, new RequestDataBag());

            $this->orderRepository->update([
                [
                    'id' => $orderId,
                    'customFields' => ['briqpay_session_id' => $sessionId],
                ],
            ], Context::createDefaultContext());

            $this->updatePaymentMethodName($sessionId, $orderId, Context::createDefaultContext());
            $this->syncBriqpayReferences($sessionId, $orderId, Context::createDefaultContext());

            // A PSP that captures on authorisation has already taken the money
            // by the time the shopper is back; record that capture now rather
            // than showing a Capture button for it.
            $session = $this->briqpayService->getSession($sessionId);
            if (!isset($session['error'])) {
                $this->captureService->syncFromSession($orderId, $session, Context::createDefaultContext());
            }

            return $this->handleSuccessRedirect($request, $orderId);
        } catch (\Exception $e) {
            $this->logger->error('Briqpay Finalize Error', [
                'sessionId' => $sessionId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $customRedirect = $request->query->get('redirectUrl');
            if ($customRedirect) {
                $separator = str_contains((string) $customRedirect, '?') ? '&' : '?';

                return $this->redirect((string) $customRedirect . $separator . 'error=1');
            }

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }
    }

    /**
     * Handles redirect after successful order creation (storefront or headless).
     */
    private function handleSuccessRedirect(Request $request, string $orderId): Response
    {
        $customRedirect = $request->query->get('redirectUrl');
        if ($customRedirect) {
            $separator = str_contains((string) $customRedirect, '?') ? '&' : '?';

            return $this->redirect((string) $customRedirect . $separator . 'orderId=' . $orderId);
        }

        return $this->redirectToRoute('frontend.checkout.finish.page', ['orderId' => $orderId]);
    }

    /**
     * Handles asynchronous payment status notifications from Briqpay.
     *
     * Briqpay webhooks are unauthenticated server-to-server POSTs (no signature),
     * so we never act on the raw POST body directly. Instead we re-fetch the
     * session from the Briqpay API and treat that as the only authoritative
     * source of truth (same compensating control the WooCommerce integration
     * uses) — the webhook body is only used to decide which session/event to
     * look at, not what actually happened to it.
     *
     * If the order was not created via 'finalize' (e.g. user closed browser),
     * this webhook triggers createOrderFromWebhook as a fallback.
     * Processes 'order_status', 'capture_status', and 'refund_status' events.
     */
    #[Route('/briqpay/webhook', name: 'frontend.briqpay.webhook', methods: ['POST'], defaults: ['csrf_protected' => false])]
    public function webhook(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $sessionId = (string) ($payload['sessionId'] ?? '');
        $status = $payload['status'] ?? '';
        $eventType = $payload['event'] ?? $payload['eventType'] ?? 'order_status';

        $this->logger->info('Briqpay: Webhook received', [
            'sessionId' => $sessionId,
            'eventType' => $eventType,
            'status' => $status,
        ]);

        if (!$sessionId) {
            return new JsonResponse(['success' => false], 400);
        }

        // Every real Briqpay session id is a UUID. Rejecting anything else here
        // costs nothing and never rejects a genuine delivery, but it does mean
        // a request that is just noise (not even shaped like a session id) is
        // answered locally instead of spending an outbound call to the Briqpay
        // API to find out it doesn't exist.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId)) {
            return new JsonResponse(['success' => false], 400);
        }

        // Briqpay may redeliver the same event (e.g. if our previous response was
        // lost). Claim a short-lived dedupe marker keyed on the event's own identity
        // so a genuine retry doesn't get processed twice; a fresh 2xx is returned
        // either way so Briqpay doesn't keep retrying a duplicate forever.
        $eventId = (string) ($payload['captureId'] ?? $payload['refundId'] ?? '');
        $dedupeKey = 'briqpay_webhook_' . md5($sessionId . '|' . $eventType . '|' . $status . '|' . $eventId);
        if (!$this->lockService->claimOnce($dedupeKey, 300)) {
            $this->logger->info('Briqpay: Duplicate webhook delivery ignored', ['sessionId' => $sessionId, 'eventType' => $eventType]);

            return new JsonResponse(['success' => true, 'message' => 'Duplicate ignored']);
        }

        $session = $this->briqpayService->getSession($sessionId);
        if (isset($session['error'])) {
            $this->logger->warning('Briqpay: Webhook session could not be verified against the Briqpay API', [
                'sessionId' => $sessionId,
                'eventType' => $eventType,
            ]);

            // 5xx tells Briqpay to retry delivery; we never act on an unverifiable session.
            return new JsonResponse(['success' => false, 'message' => 'Could not verify session'], 502);
        }

        try {
            // A lock per sessionId closes the narrow race window between this
            // fallback order-creation path and a concurrent finalize() redirect
            // for the same session (both would otherwise see "no order yet").
            return $this->lockService->withLock('briqpay_session_' . $sessionId, 30, function () use ($sessionId, $eventType, $status, $payload, $session, $salesChannelContext) {
                $context = Context::createDefaultContext();
                $order = $this->getOrderBySessionId($sessionId, $context);

                // Create order from webhook only for appropriate order_status events
                if (!$order && $eventType === 'order_status' && in_array($status, ['order_pending', 'order_approved_not_captured'], true)) {
                    $order = $this->createOrderFromWebhook($sessionId, $salesChannelContext);
                }

                if ($order) {
                    $this->processWebhookStatus($order, $eventType, $status, $payload, $session, $context);
                }

                return new JsonResponse(['success' => true]);
            });
        } catch (\Exception $e) {
            $this->logger->error('Briqpay Webhook Error', [
                'sessionId' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Processes the webhook event/status and transitions the order transaction accordingly.
     *
     * $session is the freshly re-fetched, authoritative Briqpay session — capture_status
     * and refund_status events are only acted on if their captureId/refundId actually
     * appears in it, and order_status events are checked against paymentTags.manual_review
     * before being allowed to authorize/complete the order.
     */
    private function processWebhookStatus(OrderEntity $order, string $eventType, string $status, array $payload, array $session, Context $context): void
    {
        switch ($eventType) {
            case 'order_status':
                if ($this->sessionRequiresManualReview($session) && in_array($status, ['order_pending', 'order_approved_not_captured'], true)) {
                    $this->transitionToManualReview($order, $context);
                    break;
                }

                match ($status) {
                    'order_approved_not_captured' => $this->onOrderApproved($order, $session, $context),
                    'order_rejected' => $this->transitionToFailed($order, $context),
                    'order_cancelled' => $this->transitionToCancelled($order, $context),
                    // order_pending only matters for the order-creation fallback above;
                    // there is no corresponding Shopware transaction state to move to yet.
                    'order_pending' => null,
                    default => $this->logger->info('Briqpay: Unhandled order_status webhook status', ['status' => $status])
                };
                break;

            case 'capture_status':
                $captureId = (string) ($payload['captureId'] ?? '');
                if (!$this->sessionHasCapture($session, $captureId)) {
                    $this->logger->warning('Briqpay: capture_status webhook captureId not found in verified session, ignoring', [
                        'captureId' => $captureId,
                    ]);
                    break;
                }

                match ($status) {
                    // Records the capture if it was not made from Shopware (auto
                    // capture, Briqpay dashboard) and moves the transaction to
                    // paid / paid_partially from the ledger totals.
                    'approved' => $this->captureService->syncFromSession($order->getId(), $session, $context),
                    'rejected' => $this->transitionToFailed($order, $context),
                    default => null
                };
                break;

            case 'refund_status':
                $refundId = (string) ($payload['refundId'] ?? '');
                if ($status === 'approved') {
                    if (!$this->sessionHasRefund($session, $refundId)) {
                        $this->logger->warning('Briqpay: refund_status webhook refundId not found in verified session, ignoring', [
                            'refundId' => $refundId,
                        ]);
                        break;
                    }
                    $this->captureService->syncFromSession($order->getId(), $session, $context);
                }
                break;
        }
    }

    /**
     * Reads Briqpay's manual-review flag from a session.
     *
     * Two payload shapes are accepted: a plain list of tag names
     * (paymentTags: ['manual_review']) and a map (paymentTags: {manual_review: true}).
     */
    private function sessionRequiresManualReview(array $session): bool
    {
        $tags = $session['data']['paymentTags'] ?? $session['paymentTags'] ?? null;

        if (!is_array($tags)) {
            return false;
        }

        if (in_array('manual_review', $tags, true)) {
            return true;
        }

        if (isset($tags['manual_review'])) {
            $value = $tags['manual_review'];

            return $value === true || $value === 'true' || $value === 1 || $value === '1';
        }

        return false;
    }

    /**
     * Confirms a captureId actually exists in the authoritative Briqpay session
     * (checked in both the top-level and data.captures locations, and transactions
     * as a fallback) before a capture_status webhook is trusted.
     */
    private function sessionHasCapture(array $session, string $captureId): bool
    {
        if ($captureId === '') {
            return false;
        }

        $list = $session['captures'] ?? $session['data']['captures'] ?? [];
        foreach ($list as $capture) {
            if (($capture['captureId'] ?? null) === $captureId) {
                return true;
            }
        }

        foreach ($session['data']['transactions'] ?? [] as $transaction) {
            if (($transaction['captureId'] ?? null) === $captureId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confirms a refundId actually exists in the authoritative Briqpay session
     * before a refund_status webhook is trusted.
     */
    private function sessionHasRefund(array $session, string $refundId): bool
    {
        if ($refundId === '') {
            return false;
        }

        $list = $session['refunds'] ?? $session['data']['refunds'] ?? [];
        foreach ($list as $refund) {
            if (($refund['refundId'] ?? null) === $refundId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback mechanism to create a Shopware order from an asynchronous Briqpay webhook.
     *
     * Uses cross-referenced data (SalesChannelContext token) stored in
     * Briqpay's reference2 field to recreate the checkout context for order placement.
     */
    private function createOrderFromWebhook(string $sessionId, SalesChannelContext $salesChannelContext): ?OrderEntity
    {
        $briqpaySession = $this->briqpayService->getSession($sessionId);
        $referenceData = (string) ($briqpaySession['references']['reference2'] ?? '');

        if ($referenceData === '') {
            return null;
        }

        $parts = explode('|', $referenceData);
        $swToken = $parts[0];

        $customerContext = $this->contextFactory->create($swToken, $salesChannelContext->getSalesChannelId());

        if ($customerContext->getCustomer() === null) {
            $email = $briqpaySession['data']['billing']['email'] ?? null;
            if ($email) {
                $customer = $this->getCustomerByEmail($email, Context::createDefaultContext());
                if ($customer) {
                    $customerContext = $this->contextFactory->create($swToken, $salesChannelContext->getSalesChannelId(), ['customerId' => $customer->getId()]);
                }
            }
        }

        if ($customerContext->getCustomer() !== null) {
            $cart = $this->cartService->getCart($customerContext->getToken(), $customerContext);
            if ($cart->getLineItems()->count() > 0) {
                $orderId = $this->cartService->order($cart, $customerContext, new RequestDataBag());
                $context = Context::createDefaultContext();

                $this->orderRepository->update([
                    [
                        'id' => $orderId,
                        'customFields' => ['briqpay_session_id' => $sessionId],
                    ],
                ], $context);

                $this->updatePaymentMethodName($sessionId, $orderId, $context);
                $this->syncBriqpayReferences($sessionId, $orderId, $context);

                // Clear the customer's session to prevent conflicts with consumed cart/method
                $this->contextPersister->save(
                    $customerContext->getToken(),
                    [
                        'cartToken' => null,
                        'paymentMethodId' => null,
                    ],
                    $salesChannelContext->getSalesChannelId(),
                    $customerContext->getCustomerId()
                );

                return $this->getOrderBySessionId($sessionId, $context);
            }
        }

        return null;
    }

    /**
     * Updates Briqpay session references with actual Shopware Order and Customer IDs.
     */
    private function syncBriqpayReferences(string $sessionId, string $orderId, Context $context): void
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer');
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order instanceof OrderEntity) {
            $this->briqpayService->updateReferences($sessionId, [
                'reference1' => (string) $order->getOrderNumber(),
                'orderId' => (string) $order->getId(),
                'customerId' => (string) ($order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomerId() : ''),
            ]);
        }
    }

    /**
     * Maps PSP-specific details from Briqpay to Shopware custom fields.
     *
     * Briqpay provides the actual sub-payment method used (e.g. Swish, Klarna).
     * This method stores that information on both the order and the transaction
     * to allow the PaymentNameSubscriber to show the correct name in the admin.
     */
    private function updatePaymentMethodName(string $sessionId, string $orderId, Context $context): void
    {
        try {
            $session = $this->briqpayService->getSession($sessionId);
            $data = $session['data'] ?? [];

            $transactions = $data['transactions'] ?? [];
            $lastTransaction = !empty($transactions) ? end($transactions) : null;
            $pspName = $lastTransaction['pspDisplayName'] ?? null;
            $pspIntegrationName = $lastTransaction['pspIntegrationName'] ?? null;
            $pspMetadata = $data['pspMetadata'] ?? null;

            $company = $data['company'] ?? null;
            $strongAuth = $data['strongAuth']['output'] ?? null;

            $clientToken = $session['clientToken'] ?? '';
            $merchantId = null;
            if (!empty($clientToken)) {
                $parts = explode('.', $clientToken);
                if (isset($parts[1])) {
                    $payload = json_decode(base64_decode($parts[1]), true);
                    $merchantId = $payload['merchantId'] ?? null;
                }
            }

            $isTestMode = $this->systemConfigService->getBool('BriqpayPayments.config.testMode') ? '1' : '0';

            $customFields = [
                'briqpay_session_id' => $sessionId,
                'briqpay_psp_name' => $pspName,
                'briqpay_psp_integration_name' => $pspIntegrationName,
                'briqpay_merchant_id' => $merchantId,
                'briqpay_test_mode' => $isTestMode,
                'briqpay_psp_metadata' => $pspMetadata,
                'briqpay_company' => $company,
                'briqpay_strong_auth' => $strongAuth,
            ];

            // Save on the transaction
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $transactionId = $this->transactionRepository->searchIds($criteria, $context)->firstId();

            if ($transactionId) {
                $update = ['id' => $transactionId, 'customFields' => $customFields];

                // The order/cart's payment_method_id reflects whatever Shopware
                // sales-channel-level default was in effect at order placement —
                // if Briqpay isn't the only active payment method (or isn't the
                // configured default), that can end up being a completely
                // different method even though Briqpay actually processed the
                // payment (confirmed live: orders came through tagged "Cash on
                // delivery"). We know for certain this order went through
                // Briqpay (we have a verified session for it right here), so
                // correct the stored payment method to match reality.
                $briqpayPaymentMethodId = $this->getBriqpayPaymentMethodId($context);
                if ($briqpayPaymentMethodId) {
                    $update['paymentMethodId'] = $briqpayPaymentMethodId;
                }

                $this->transactionRepository->update([$update], $context);
            }

            // Save on the order
            $this->orderRepository->update([
                [
                    'id' => $orderId,
                    'customFields' => $customFields,
                ],
            ], $context);

            // Top up the order's actual billing/shipping address from Briqpay's own
            // copy — see BriqpayAddressSyncService for why this only ever fills in
            // fields Briqpay actually returned, never blanks anything out.
            $this->addressSyncService->syncFromSession($orderId, $session, $context);

        } catch (\Exception $e) {
            $this->logger->error('Briqpay: Could not update payment data', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Finder for Customer entity by email address.
     */
    private ?string $briqpayPaymentMethodIdCache = null;

    /**
     * Resolves (and caches for the lifetime of this request) the ID of the
     * Briqpay payment method row registered by this plugin.
     */
    private function getBriqpayPaymentMethodId(Context $context): ?string
    {
        if ($this->briqpayPaymentMethodIdCache !== null) {
            return $this->briqpayPaymentMethodIdCache;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', BriqpayPaymentHandler::class));

        return $this->briqpayPaymentMethodIdCache = $this->paymentMethodRepository->searchIds($criteria, $context)->firstId();
    }

    private function getCustomerByEmail(string $email, Context $context): ?CustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $email));

        $customer = $this->customerRepository->search($criteria, $context)->first();

        return $customer instanceof CustomerEntity ? $customer : null;
    }

    /**
     * Finder for Order entity by its associated Briqpay session ID.
     */
    private function getOrderBySessionId(string $sessionId, Context $context): ?OrderEntity
    {
        if (empty($sessionId)) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.briqpay_session_id', $sessionId));
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->setLimit(1);

        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    /**
     * Transitions an order transaction to 'authorized' state.
     */
    private function transitionToAuthorized(OrderEntity $order, Context $context): void
    {
        $transactions = $order->getTransactions();
        if (!$transactions || $transactions->count() === 0) {
            return;
        }

        $transaction = $transactions->last();
        $state = $transaction->getStateMachineState()?->getTechnicalName();

        // Only a transaction that is still waiting can be authorised. One that
        // is already paid (auto-captured before the approval webhook arrived)
        // or refunded must not be pulled back.
        if (\in_array($state, ['open', 'in_progress', 'unconfirmed', 'reminded'], true)) {
            $this->stateHandler->authorize($transaction->getId(), $context);
        }
    }

    /**
     * Briqpay approved the order: authorise the transaction, then record any
     * capture the PSP made on authorisation so the order shows as paid with
     * the capture in its ledger, not as authorised with a Capture button.
     */
    private function onOrderApproved(OrderEntity $order, array $session, Context $context): void
    {
        $this->transitionToAuthorized($order, $context);
        $this->captureService->syncFromSession($order->getId(), $session, $context);
    }

    /**
     * Transitions an order transaction to 'failed' state.
     */
    private function transitionToFailed(OrderEntity $order, Context $context): void
    {
        $transactions = $order->getTransactions();
        if (!$transactions || $transactions->count() === 0) {
            return;
        }

        $transaction = $transactions->last();
        if (!in_array($transaction->getStateMachineState()->getTechnicalName(), ['failed', 'cancelled', 'paid'])) {
            $this->stateHandler->fail($transaction->getId(), $context);
        }
    }

    /**
     * Transitions an order transaction to 'cancelled' state.
     */
    private function transitionToCancelled(OrderEntity $order, Context $context): void
    {
        $transactions = $order->getTransactions();
        if (!$transactions || $transactions->count() === 0) {
            return;
        }

        $transaction = $transactions->last();
        if (!in_array($transaction->getStateMachineState()->getTechnicalName(), ['cancelled', 'failed', 'paid'])) {
            $this->stateHandler->cancel($transaction->getId(), $context);
        }
    }

    /**
     * Parks an order transaction in a hold state pending human review.
     *
     * Shopware's payment transaction state machine has no dedicated "on hold"
     * state; 'reminded' is the closest non-terminal state that doesn't imply
     * success or failure, so we (ab)use it to flag "a human needs to look at
     * this before it progresses further" — mirroring what session.data.paymentTags
     * .manual_review means in the Briqpay session.
     */
    private function transitionToManualReview(OrderEntity $order, Context $context): void
    {
        $transactions = $order->getTransactions();
        if (!$transactions || $transactions->count() === 0) {
            return;
        }

        $transaction = $transactions->last();
        $currentState = $transaction->getStateMachineState()->getTechnicalName();

        if (!in_array($currentState, ['reminded', 'paid', 'paid_partially', 'cancelled', 'failed', 'refunded', 'refunded_partially'], true)) {
            try {
                $this->stateHandler->remind($transaction->getId(), $context);
                $this->logger->info('Briqpay: Order flagged for manual review, holding', ['orderId' => $order->getId()]);
            } catch (\Exception $e) {
                $this->logger->warning('Briqpay: Could not transition to manual-review hold', [
                    'transactionId' => $transaction->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // Paid and refunded transitions are no longer made here: both are driven
    // from the local ledger by BriqpayCaptureService::syncFromSession(), which
    // knows whether the captured/refunded totals amount to a full or a partial
    // settlement and which state machine action reaches the target state from
    // where the transaction currently is.
}
