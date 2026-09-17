<?php declare(strict_types=1);

namespace Briqpay\Payments\Test\Components;

use Briqpay\Payments\Components\BriqpayRequestFactory;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * @covers \Briqpay\Payments\Components\BriqpayRequestFactory
 */
class BriqpayRequestFactoryTest extends TestCase
{
    private BriqpayRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new BriqpayRequestFactory(
            $this->createMock(SystemConfigService::class),
            $this->createMock(PluginService::class),
            '6.6.0.0'
        );
    }

    private function contextWithCurrencyDecimals(int $decimals, string $isoCode = 'SEK'): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode($isoCode);
        $currency->setTotalRounding(new CashRoundingConfig($decimals, 0.01, false));

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($currency);

        return $context;
    }

    public function testAllowsTwoDecimalCurrency(): void
    {
        $this->factory->assertSupportedCurrencyPrecision($this->contextWithCurrencyDecimals(2, 'SEK'));
        $this->addToAssertionCount(1); // No exception thrown = pass.
    }

    public function testRejectsZeroDecimalCurrency(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JPY');

        $this->factory->assertSupportedCurrencyPrecision($this->contextWithCurrencyDecimals(0, 'JPY'));
    }

    public function testRejectsThreeDecimalCurrency(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('KWD');

        $this->factory->assertSupportedCurrencyPrecision($this->contextWithCurrencyDecimals(3, 'KWD'));
    }

    public function testCalculateTotalsSumsIncAndExVat(): void
    {
        $items = [
            ['unitPrice' => 10000, 'quantity' => 2, 'taxRate' => 2500], // 100.00 net x2, 25% VAT
            ['unitPrice' => 5000, 'quantity' => 1, 'taxRate' => 0],
        ];

        $totals = $this->factory->calculateTotals($items);

        $this->assertSame(25000, $totals['amountExVat']);
        $this->assertSame(30000, $totals['amountIncVat']);
    }

    public function testResolveReferenceUsesProductNumberForProducts(): void
    {
        $this->assertSame(
            'SWDEMO10001',
            $this->factory->resolveReference('product', ['productNumber' => 'SWDEMO10001'], 'fallback-id')
        );
    }

    /**
     * Shopware appends a variant suffix after a dot; Briqpay gets the part
     * before it, because that is what the capture is matched against.
     */
    public function testResolveReferenceStripsVariantSuffix(): void
    {
        $this->assertSame(
            'SWDEMO10001',
            $this->factory->resolveReference('product', ['productNumber' => 'SWDEMO10001.2'], null)
        );
    }

    /**
     * A promotion travels as an ordinary cart line with a negative amount,
     * referenced by its code -- the convention the WooCommerce integration
     * established. Without this a discount line reached Briqpay with the
     * promotion's UUID, which no capture could then match.
     */
    public function testResolveReferencePrefixesPromotionsWithDiscount(): void
    {
        $this->assertSame(
            'discount_SUMMER20',
            $this->factory->resolveReference('promotion', ['code' => 'SUMMER20'], 'promo-uuid')
        );
    }

    public function testResolveReferenceFallsBackToPromotionIdForAutomaticDiscounts(): void
    {
        $this->assertSame(
            'discount_promo-uuid',
            $this->factory->resolveReference('promotion', ['promotionId' => 'promo-uuid'], null)
        );
    }

    /**
     * A coupon code may contain a dot, and unlike a product number that dot is
     * part of the code rather than a variant suffix.
     */
    public function testResolveReferenceKeepsDotsInDiscountCodes(): void
    {
        $this->assertSame(
            'discount_SAVE.10',
            $this->factory->resolveReference('promotion', ['code' => 'SAVE.10'], null)
        );
    }

    public function testMapCaptureItemsKeepsDiscountReferencesIntact(): void
    {
        $mapped = $this->factory->mapCaptureItems([
            ['reference' => 'discount_SAVE.10', 'name' => 'Discount', 'quantity' => 1, 'unitPrice' => -2000, 'taxRate' => 2500, 'type' => 'physical'],
            ['reference' => 'SWDEMO10001.2', 'name' => 'Main product', 'quantity' => 1, 'unitPrice' => 10000, 'taxRate' => 2500, 'type' => 'physical'],
        ]);

        $this->assertSame('discount_SAVE.10', $mapped[0]['reference']);
        $this->assertSame('SWDEMO10001', $mapped[1]['reference']);
    }

    /**
     * A negative line has to keep its sign all the way through, or a cart with
     * a discount reconciles to more than the shopper actually pays.
     */
    public function testMapCaptureItemsKeepsNegativeAmountsNegative(): void
    {
        $mapped = $this->factory->mapCaptureItems([
            ['reference' => 'discount_SUMMER20', 'name' => 'Discount', 'quantity' => 1, 'unitPrice' => -2000, 'taxRate' => 2500, 'type' => 'physical'],
        ]);

        $this->assertSame(-2000, $mapped[0]['unitPrice']);
        $this->assertSame(-500, $mapped[0]['totalVatAmount']);
        $this->assertSame(-2500, $mapped[0]['totalAmount']);
    }

    /**
     * Discounts and odd shipping prices are exactly where per-line rounding
     * drifts from a single rounding of the order total. The totals Briqpay is
     * given must be the sum of the lines it is given, since Briqpay reconciles
     * the two itself and refuses a session whose total does not add up.
     */
    public function testCalculateTotalsReconcilesWithMixedDiscountAndShippingLines(): void
    {
        $items = [
            ['unitPrice' => 41676, 'quantity' => 3, 'taxRate' => 1900],  // 416.76 net x3 @19%
            ['unitPrice' => -8333, 'quantity' => 1, 'taxRate' => 1900],  // -83.33 net discount
            ['unitPrice' => 1261, 'quantity' => 1, 'taxRate' => 1900],   // 12.61 net shipping
        ];

        $totals = $this->factory->calculateTotals($items);

        $expectedNet = 41676 * 3 - 8333 + 1261;
        $this->assertSame($expectedNet, $totals['amountExVat']);

        // Every line's VAT is rounded on its own and the total is their sum,
        // which is what Briqpay checks the cart against.
        $expectedGross = 0;
        foreach ($items as $item) {
            $net = $item['unitPrice'] * $item['quantity'];
            $expectedGross += $net + (int) round($net * ($item['taxRate'] / 10000));
        }
        $this->assertSame($expectedGross, $totals['amountIncVat']);
    }

    /**
     * A gross-priced line the way Shopware calculates one: the shopper sees
     * $gross per unit, the line total is $gross * $quantity, and the VAT is
     * whatever that total contains.
     */
    private function grossLine(string $productNumber, float $gross, int $quantity, float $taxRate, string $label = 'Item'): LineItem
    {
        $total = $gross * $quantity;
        $tax = $total - $total / (1 + $taxRate / 100);

        $item = new LineItem($productNumber, LineItem::PRODUCT_LINE_ITEM_TYPE, $productNumber, $quantity);
        $item->setLabel($label);
        $item->setPayload(['productNumber' => $productNumber]);
        $item->setPrice(new CalculatedPrice(
            $gross,
            $total,
            new CalculatedTaxCollection([new CalculatedTax($tax, $taxRate, $total)]),
            new TaxRuleCollection()
        ));

        return $item;
    }

    private function promotionLine(string $code, float $gross, float $taxRate): LineItem
    {
        $tax = $gross - $gross / (1 + $taxRate / 100);

        $item = new LineItem('promotion-' . $code, LineItem::PROMOTION_LINE_ITEM_TYPE, $code, 1);
        $item->setLabel('Discount');
        $item->setPayload(['code' => $code, 'promotionId' => 'promo-uuid']);
        $item->setPrice(new CalculatedPrice(
            $gross,
            $gross,
            new CalculatedTaxCollection([new CalculatedTax($tax, $taxRate, $gross)]),
            new TaxRuleCollection()
        ));

        return $item;
    }

    private function shippingPrice(float $gross, float $taxRate): CalculatedPrice
    {
        $tax = $gross - $gross / (1 + $taxRate / 100);

        return new CalculatedPrice(
            $gross,
            $gross,
            new CalculatedTaxCollection([new CalculatedTax($tax, $taxRate, $gross)]),
            new TaxRuleCollection()
        );
    }

    /**
     * Regression test for a drift confirmed live against the Briqpay
     * playground. Every line total used to be rebuilt from a per-unit net price
     * rounded to whole minor units, which cannot reproduce the line total a
     * gross-priced shop charges -- 19.99 at 19% VAT has a net value of
     * 16.798..., and rounding that before multiplying by the quantity loses
     * fractions that accumulate. On a cart of three products, a percentage
     * discount and a paid shipping line the shopper was billed 940.53 for an
     * order Shopware had recorded as 940.52.
     */
    public function testEveryLineTotalMatchesShopwaresOwnLineTotal(): void
    {
        $items = $this->factory->mapItems(
            new LineItemCollection([
                $this->grossLine('SWDEMO10005', 19.99, 3, 19.0),
                $this->grossLine('SWDEMO10001', 495.95, 2, 19.0),
                $this->grossLine('SWDEMO10006', 20.00, 1, 19.0),
                $this->promotionLine('ROUND13', -139.34, 19.0),
            ]),
            $this->shippingPrice(7.99, 19.0)
        );

        $expected = [5997, 99190, 2000, -13934, 799];
        $this->assertSame($expected, array_column($items, 'totalAmount'));
    }

    /**
     * The cart Briqpay is given has to add up to the order total it is given in
     * the same payload. They were computed two different ways, so a shipping
     * line whose total came from Shopware (799) was counted as 798 in the
     * order total -- the payload contradicted itself before it was ever sent.
     */
    public function testOrderTotalIsExactlyTheSumOfTheCartItSentWith(): void
    {
        $items = $this->factory->mapItems(
            new LineItemCollection([
                $this->grossLine('SWDEMO10005', 19.99, 3, 19.0),
                $this->grossLine('SWDEMO10001', 495.95, 2, 19.0),
                $this->grossLine('SWDEMO10006', 20.00, 1, 19.0),
                $this->promotionLine('ROUND13', -139.34, 19.0),
            ]),
            $this->shippingPrice(7.99, 19.0)
        );

        $totals = $this->factory->calculateTotals($items);

        $this->assertSame(array_sum(array_column($items, 'totalAmount')), $totals['amountIncVat']);
        $this->assertSame(
            array_sum(array_column($items, 'totalAmount')) - array_sum(array_column($items, 'totalVatAmount')),
            $totals['amountExVat']
        );

        // And that sum is what Shopware charges: 59.97 + 991.90 + 20.00
        // - 139.34 + 7.99 = 940.52.
        $this->assertSame(94052, $totals['amountIncVat']);
    }

    /**
     * The same cart in a 25% VAT market, to show the reconciliation is not a
     * property of one tax rate.
     */
    public function testTotalsReconcileAtADifferentVatRate(): void
    {
        $items = $this->factory->mapItems(
            new LineItemCollection([
                $this->grossLine('A', 19.99, 7, 25.0),
                $this->grossLine('B', 33.33, 3, 25.0),
                $this->promotionLine('SAVE', -24.44, 25.0),
            ]),
            $this->shippingPrice(49.90, 25.0)
        );

        $totals = $this->factory->calculateTotals($items);

        $this->assertSame(array_sum(array_column($items, 'totalAmount')), $totals['amountIncVat']);
        // 139.93 + 99.99 - 24.44 + 49.90
        $this->assertSame(26538, $totals['amountIncVat']);
    }

    /**
     * A discount reaches Briqpay as an ordinary line with a negative total,
     * referenced by its coupon code so a capture can match it.
     */
    public function testDiscountLineIsSentAsANegativeLineReferencedByItsCode(): void
    {
        $items = $this->factory->mapItems(
            new LineItemCollection([
                $this->grossLine('SWDEMO10001', 495.95, 1, 19.0),
                $this->promotionLine('ROUND13', -64.47, 19.0),
            ])
        );

        $discount = $items[1];
        $this->assertSame('discount_ROUND13', $discount['reference']);
        $this->assertSame(-6447, $discount['totalAmount']);
        $this->assertLessThan(0, $discount['unitPrice']);
    }

    /**
     * Free shipping is not a cart line at all: sending a zero line makes the
     * later capture fail with CART_ITEM_NOT_FOUND, which was confirmed live.
     */
    public function testFreeShippingIsNotSentAsACartLine(): void
    {
        $items = $this->factory->mapItems(
            new LineItemCollection([$this->grossLine('SWDEMO10001', 495.95, 1, 19.0)]),
            $this->shippingPrice(0.0, 19.0)
        );

        $this->assertCount(1, $items);
        $this->assertSame([], array_filter($items, fn (array $i) => $i['reference'] === 'shipping'));
    }
}
