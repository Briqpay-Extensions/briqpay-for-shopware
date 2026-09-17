<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Controller;

use Briqpay\Payments\Controller\BriqpayDecisionController;
use Briqpay\Payments\Service\BriqpaySessionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the session-ownership check added to the decision endpoint: since it's
 * a public/unauthenticated endpoint the Briqpay iframe calls directly from the
 * browser, a request must not be able to name an arbitrary sessionId it doesn't
 * own and get a real allow/reject decision issued against it.
 *
 * @covers \Briqpay\Payments\Controller\BriqpayDecisionController
 */
class BriqpayDecisionControllerTest extends TestCase
{
    private $briqpayService;
    private $cartService;
    private $logger;
    private $contextPersister;
    private $controller;
    private $context;

    protected function setUp(): void
    {
        $this->briqpayService = $this->createMock(BriqpaySessionService::class);
        $this->cartService = $this->createMock(CartService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->contextPersister = $this->createMock(SalesChannelContextPersister::class);

        $this->controller = new BriqpayDecisionController(
            $this->briqpayService,
            $this->cartService,
            $this->logger,
            $this->contextPersister
        );

        $this->context = $this->createMock(SalesChannelContext::class);
        $this->context->method('getToken')->willReturn('cart-token');
        $this->context->method('getSalesChannelId')->willReturn('sales-channel-1');
        $this->context->method('getCustomerId')->willReturn('customer-1');
    }

    private function jsonRequest(array $payload): Request
    {
        return new Request([], [], [], [], [], [], json_encode($payload));
    }

    public function testRejectsSessionIdNotOwnedByCurrentCart(): void
    {
        $this->contextPersister->method('load')->willReturn(['briqpay_session_id' => 'sess-real']);

        $this->briqpayService->expects($this->never())->method('getSession');
        $this->briqpayService->expects($this->never())->method('postDecision');

        $request = $this->jsonRequest(['sessionId' => 'sess-fabricated']);
        $response = $this->controller->validate($request, $this->context);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testRejectsWhenCartHasNoOwnedSessionAtAll(): void
    {
        $this->contextPersister->method('load')->willReturn([]);

        $request = $this->jsonRequest(['sessionId' => 'sess-anything']);
        $response = $this->controller->validate($request, $this->context);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testValidatesMatchingOwnedSession(): void
    {
        $this->contextPersister->method('load')->willReturn(['briqpay_session_id' => 'sess-real']);

        $cart = new Cart('cart-token');
        $cart->setLineItems(new LineItemCollection());
        $this->cartService->method('getCart')->willReturn($cart);

        $this->briqpayService->method('getSession')->willReturn([
            'data' => ['order' => ['amountIncVat' => 1000]],
        ]);
        $this->briqpayService->method('buildPayload')->willReturn([
            'data' => ['order' => ['amountIncVat' => 1000]],
        ]);

        $this->briqpayService->expects($this->once())->method('postDecision')->with('sess-real', $cart, $this->context, false);

        $request = $this->jsonRequest(['sessionId' => 'sess-real']);
        $response = $this->controller->validate($request, $this->context);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        // Cart is empty in this test, so validation itself still correctly says "false".
        $this->assertFalse($data['validated']);
    }
}
