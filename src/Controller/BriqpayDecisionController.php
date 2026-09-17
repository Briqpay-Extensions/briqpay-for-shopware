<?php declare(strict_types=1);

namespace Briqpay\Payments\Controller;

use Briqpay\Payments\Service\BriqpaySessionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for validating Briqpay's external payment decisions.
 *
 * Briqpay calls the 'validate' endpoint (mapped to the decision URL)
 * before finalizing a payment. This controller ensures that the payment about
 * to be made matches the current state of the Shopware cart (amount, items).
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BriqpayDecisionController extends StorefrontController
{
    /**
     * @param BriqpaySessionService $briqpayService
     * @param CartService           $cartService
     * @param LoggerInterface       $logger
     */
    public function __construct(
        private readonly BriqpaySessionService $briqpayService,
        private readonly CartService $cartService,
        private readonly LoggerInterface $logger,
        private readonly SalesChannelContextPersister $contextPersister
    ) {
    }

    /**
     * Validates the consistency between the Briqpay session and the Shopware cart.
     *
     * This is a critical security check. If the amounts differ or the
     * cart is empty, we return 'validated: false' to prevent mismatched payments.
     * Dispatches BriqpayDecisionEvent via postDecision to allow external overrides.
     *
     * @param Request             $request Incoming POST request from Briqpay.
     * @param SalesChannelContext $context Active sales channel context.
     *
     * @return JsonResponse JSON response indicating validation status.
     */
    #[Route(
        path: '/briqpay/decision',
        name: 'frontend.briqpay.decision',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function validate(Request $request, SalesChannelContext $context): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $sessionId = $content['sessionId'] ?? null;

        if (!$sessionId) {
            return new JsonResponse(['success' => false, 'message' => 'Missing sessionId'], 400);
        }

        // The decision endpoint is public/unauthenticated (Briqpay's iframe calls it
        // directly from the browser), so a request could name an arbitrary sessionId
        // it doesn't own. Only ever decide on the session actually tied to the
        // current cart/context — never trust the posted sessionId on its own.
        $contextData = $this->contextPersister->load($context->getToken(), $context->getSalesChannelId(), $context->getCustomerId());
        $ownedSessionId = $contextData['briqpay_session_id'] ?? null;

        if (!$ownedSessionId || !hash_equals((string) $ownedSessionId, (string) $sessionId)) {
            $this->logger->warning('Briqpay Decision: sessionId does not belong to the current cart context', [
                'postedSessionId' => $sessionId,
                'ownedSessionId' => $ownedSessionId,
            ]);

            return new JsonResponse(['success' => false, 'message' => 'Session does not belong to this cart'], 403);
        }

        try {
            $briqpaySession = $this->briqpayService->getSession($sessionId);
            $cart = $this->cartService->getCart($context->getToken(), $context);

            $expectedPayload = $this->briqpayService->buildPayload($cart, $context);
            $expectedAmount = $expectedPayload['data']['order']['amountIncVat'] ?? 0;

            $briqpayAmountInCents = $briqpaySession['data']['order']['amountIncVat'] ?? 0;
            $itemCount = $cart->getLineItems()->count();

            $isValid = ($itemCount > 0) && ($expectedAmount === $briqpayAmountInCents);

            if (!$isValid) {
                $this->logger->warning('Briqpay Decision Mismatch', [
                    'sessionId' => $sessionId,
                    'reason' => $itemCount === 0 ? 'Empty cart' : 'Amount mismatch',
                    'expectedAmount' => $expectedAmount,
                    'briqpayAmount' => $briqpayAmountInCents,
                    'diff' => $expectedAmount - $briqpayAmountInCents,
                    'cartItems' => $itemCount,
                ]);
            }

            $this->briqpayService->postDecision($sessionId, $cart, $context, $isValid);

            return new JsonResponse([
                'success' => true,
                'validated' => $isValid,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Briqpay Decision Exception', [
                'sessionId' => $sessionId,
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
