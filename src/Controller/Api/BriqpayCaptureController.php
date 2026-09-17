<?php declare(strict_types=1);

namespace Briqpay\Payments\Controller\Api;

use Briqpay\Payments\Service\BriqpayCaptureService;
use Briqpay\Payments\Service\BriqpayHostedPageService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API controller for Briqpay capture, refund, cancel, and hosted-page operations.
 * Routes are scoped under /api/_action/briqpay/.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class BriqpayCaptureController extends AbstractController
{
    public function __construct(
        private readonly BriqpayCaptureService $captureService,
        private readonly BriqpayHostedPageService $hostedPageService
    ) {
    }

    /**
     * Captures (full or partial) an authorized Briqpay order.
     */
    #[Route(
        path: '/api/_action/briqpay/capture',
        name: 'api.action.briqpay.capture',
        methods: ['POST']
    )]
    public function capture(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $transactionId = $data['transactionId'] ?? null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $items = $data['items'] ?? [];

        if (!$transactionId || $amount === null) {
            return new JsonResponse(['error' => 'Missing transactionId or amount'], 400);
        }

        try {
            $captureId = $this->captureService->capture($transactionId, $amount, $items, false, $context);

            return new JsonResponse(['success' => true, 'captureId' => $captureId]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Refunds (full or partial) a captured Briqpay order.
     * Requires captureId to scope the refund to a specific capture.
     */
    #[Route(
        path: '/api/_action/briqpay/refund',
        name: 'api.action.briqpay.refund',
        methods: ['POST']
    )]
    public function refund(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $transactionId = $data['transactionId'] ?? null;
        $captureId = $data['captureId'] ?? null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $items = $data['items'] ?? [];

        if (!$transactionId || !$captureId || $amount === null) {
            return new JsonResponse(['error' => 'Missing transactionId, captureId, or amount'], 400);
        }

        try {
            $refundId = $this->captureService->refund($transactionId, $captureId, $amount, $items, $context);

            return new JsonResponse(['success' => true, 'refundId' => $refundId]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancels (voids) a Briqpay order before any capture has been made.
     */
    #[Route(
        path: '/api/_action/briqpay/cancel',
        name: 'api.action.briqpay.cancel',
        methods: ['POST']
    )]
    public function cancel(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $transactionId = $data['transactionId'] ?? null;

        if (!$transactionId) {
            return new JsonResponse(['error' => 'Missing transactionId'], 400);
        }

        try {
            $this->captureService->cancel($transactionId, $context);

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Creates a Briqpay hosted payment page ("pay by link") for an existing
     * order — for orders placed without Briqpay (e.g. a manual/phone order) or
     * whose original payment attempt failed, so the merchant can send the
     * customer a link to pay via Briqpay after the fact.
     */
    #[Route(
        path: '/api/_action/briqpay/hosted-page',
        name: 'api.action.briqpay.hosted_page',
        methods: ['POST']
    )]
    public function hostedPage(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $orderId = $data['orderId'] ?? null;

        if (!$orderId) {
            return new JsonResponse(['error' => 'Missing orderId'], 400);
        }

        try {
            $result = $this->hostedPageService->createHostedPage($orderId, $context);

            return new JsonResponse(['success' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Fetches all capture/refund records for a transaction.
     */
    #[Route(
        path: '/api/_action/briqpay/list/{transactionId}',
        name: 'api.action.briqpay.list',
        methods: ['GET']
    )]
    public function list(string $transactionId): JsonResponse
    {
        try {
            $records = $this->captureService->getRecords($transactionId);

            return new JsonResponse([
                'success' => true,
                'records' => $records,
                // The card decides which buttons to offer from this rather than
                // from the order it was rendered with, so a cancel or capture
                // is reflected without a page reload.
                'transactionState' => $this->captureService->getTransactionState($transactionId),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
