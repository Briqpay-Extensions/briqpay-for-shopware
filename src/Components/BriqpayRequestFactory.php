<?php declare(strict_types=1);

namespace Briqpay\Payments\Components;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Factory for building Briqpay API request payloads from Shopware data.
 * Handles line item mapping, address mapping, totals calculation, and API credentials.
 */
class BriqpayRequestFactory
{
    private string $shopwareVersion;
    private ?string $pluginVersion = null;

    public function __construct(
        private readonly SystemConfigService $configService,
        private readonly PluginService $pluginService,
        string $shopwareVersion
    ) {
        $this->shopwareVersion = $shopwareVersion;
    }

    /**
     * Maps Shopware line items and shipping costs to the Briqpay cart format.
     *
     * All monetary values are converted to minor units (cents/öre).
     * Tax rates are expressed as integer basis points (e.g. 25% = 2500).
     *
     * @param iterable              $lineItems     Shopware cart line items.
     * @param CalculatedPrice|float $shippingCosts Shipping cost object or legacy float.
     *
     * @return array Briqpay-formatted cart items.
     */
    public function mapItems(iterable $lineItems, $shippingCosts = 0.0): array
    {
        $items = [];
        foreach ($lineItems as $item) {
            $price = $item->getPrice();
            if (!$price) {
                continue;
            }

            $taxRate = 0;
            if ($price->getCalculatedTaxes()->count() > 0) {
                $taxRate = $price->getCalculatedTaxes()->first()->getTaxRate();
            }

            $payload = $item->getPayload() ?? [];
            $reference = $this->resolveReference(
                $item->getType(),
                $payload,
                $item instanceof LineItem ? $item->getReferencedId() : $item->getProductId()
            );

            $items[] = $this->buildLine(
                $reference,
                (string) $item->getLabel(),
                $item->getQuantity(),
                $price->getTotalPrice(),
                $price->getCalculatedTaxes()->getAmount(),
                $taxRate
            );
        }

        // Handle shipping costs regardless of whether it is an object or float
        if ($shippingCosts instanceof CalculatedPrice) {
            if ($shippingCosts->getTotalPrice() > 0) {
                $items[] = $this->createShippingItem($shippingCosts);
            }
        } elseif (is_numeric($shippingCosts) && $shippingCosts > 0) {
            // Fallback for legacy float values to prevent crashes
            $items[] = $this->createShippingItemFromFloat((float) $shippingCosts);
        }

        return $items;
    }

    /**
     * Maps an already-persisted Shopware order's line items to the Briqpay cart
     * format — used by the hosted-page (pay-by-link) flow, where there is no
     * Cart, only an existing OrderEntity. Mirrors mapItems()'s rounding logic.
     *
     * @param iterable<\Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity> $lineItems
     */
    public function mapOrderItems(iterable $lineItems, ?CalculatedPrice $shippingCosts = null): array
    {
        $items = [];
        foreach ($lineItems as $item) {
            $price = $item->getPrice();
            if (!$price) {
                continue;
            }

            $taxRate = 0;
            if ($price->getCalculatedTaxes()->count() > 0) {
                $taxRate = $price->getCalculatedTaxes()->first()->getTaxRate();
            }

            $payload = $item->getPayload() ?? [];
            $reference = $this->resolveReference($item->getType(), $payload, $item->getProductId() ?? $item->getIdentifier());

            $items[] = $this->buildLine(
                $reference,
                (string) $item->getLabel(),
                $item->getQuantity(),
                $price->getTotalPrice(),
                $price->getCalculatedTaxes()->getAmount(),
                $taxRate
            );
        }

        if ($shippingCosts && $shippingCosts->getTotalPrice() > 0) {
            $items[] = $this->createShippingItem($shippingCosts);
        }

        return $items;
    }

    /**
     * Maps a Shopware order address (billing/shipping snapshot on the order,
     * as opposed to a live customer address) to the Briqpay address format.
     */
    public function mapOrderAddress(?OrderAddressEntity $address, ?string $email): array
    {
        if (!$address) {
            return [];
        }

        $data = [
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'streetAddress' => $address->getStreet(),
            'city' => $address->getCity(),
            'zip' => $address->getZipcode(),
            'country' => $address->getCountry()?->getIso(),
            'email' => $email,
        ];

        if (!empty($address->getPhoneNumber())) {
            $data['phoneNumber'] = $address->getPhoneNumber();
        }

        return $data;
    }

    /**
     * Maps raw capture/refund item data into the Briqpay cart line format.
     *
     * @param array $items Raw items from the admin UI capture/refund modal.
     *
     * @return array Briqpay-formatted items.
     */
    public function mapCaptureItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $unitPriceMinor = (int) $item['unitPrice'];
            $taxRateMinor = (int) ($item['taxRate'] ?? 0);
            $quantity = (int) $item['quantity'];

            $reference = ($item['type'] === 'shipping_fee') ? 'shipping' : $this->normaliseReference((string) $item['reference']);

            // Prefer the totals the caller worked out from Shopware's own line
            // figures. Recomputing them from the per-unit price reintroduces the
            // rounding drift that makes a capture disagree with the session it
            // is being matched against, which Briqpay refuses.
            if (isset($item['totalAmount'])) {
                $totalAmountIncVat = (int) $item['totalAmount'];
                $totalVatAmount = (int) ($item['totalVatAmount'] ?? round($totalAmountIncVat * ($taxRateMinor / (10000 + $taxRateMinor))));
            } else {
                $totalAmountNet = $unitPriceMinor * $quantity;
                $totalVatAmount = (int) round($totalAmountNet * ($taxRateMinor / 10000));
                $totalAmountIncVat = $totalAmountNet + $totalVatAmount;
            }

            $mapped[] = [
                'productType' => $item['type'] ?? 'physical',
                'reference' => $reference,
                'quantityUnit' => 'pc',
                'name' => mb_substr($item['name'] ?? 'Item', 0, 128),
                'quantity' => $quantity,
                'unitPrice' => $unitPriceMinor,
                'taxRate' => $taxRateMinor,
                'totalAmount' => $totalAmountIncVat,
                'totalVatAmount' => $totalVatAmount,
                'discountPercentage' => 0,
            ];
        }

        return $mapped;
    }

    /**
     * The reference Briqpay knows a cart line by.
     *
     * Products use their product number. Promotions use "discount_<code>" --
     * the convention the WooCommerce integration established -- so a discount
     * travels as an ordinary line with negative amounts and can be captured and
     * refunded like any other. Captures are matched to session lines by this
     * reference, so the admin component applies the same rule.
     */
    public function resolveReference(?string $type, array $payload, ?string $fallback): string
    {
        if ($type === LineItem::PROMOTION_LINE_ITEM_TYPE) {
            $code = trim((string) ($payload['code'] ?? ''));

            return 'discount_' . ($code !== '' ? $code : (string) ($payload['promotionId'] ?? $fallback ?? 'promotion'));
        }

        return $this->normaliseReference((string) ($payload['productNumber'] ?? $fallback ?? ''));
    }

    /**
     * Product numbers can carry a variant suffix after a dot; Briqpay gets the
     * part before it. Discount references are used verbatim -- a coupon code
     * may legitimately contain a dot.
     */
    public function normaliseReference(string $reference): string
    {
        if (str_starts_with($reference, 'discount_')) {
            return $reference;
        }

        $head = strtok($reference, '.');

        return $head === false ? '' : $head;
    }

    /**
     * Builds one Briqpay cart line from Shopware's own figures for it.
     *
     * The line total and its VAT are taken from Shopware and only converted to
     * minor units; they are never recomputed from a per-unit price. Shopware is
     * a gross-priced shop by default, so a unit price of 19.99 at 19% VAT has a
     * net value of 16.798..., and rounding that to whole minor units before
     * multiplying by the quantity does not reproduce the line total Shopware
     * charges. Confirmed live on a cart of three products, a percentage
     * discount and a paid shipping line: each line drifted by up to a minor
     * unit and the drifts accumulated, so the shopper was billed 940.53 for an
     * order Shopware recorded as 940.52.
     *
     * The per-unit net price is therefore derived from the line, not the other
     * way round. It is what a later partial capture of n units is priced from,
     * which is exactly the residue BriqpayCaptureService's rounding tolerance
     * exists to absorb.
     *
     * @param float $totalGross Line total including VAT, in major units
     * @param float $totalTax   VAT included in that total, in major units
     * @param float $taxRate    Percentage, e.g. 19.0
     */
    private function buildLine(
        string $reference,
        string $name,
        int $quantity,
        float $totalGross,
        float $totalTax,
        float $taxRate,
        string $productType = 'physical'
    ): array {
        $quantity = max($quantity, 1);

        $totalAmount = (int) round($totalGross * 100);
        $totalVatAmount = (int) round($totalTax * 100);
        $totalNet = $totalAmount - $totalVatAmount;

        return [
            'productType' => $productType,
            'reference' => $reference,
            'name' => mb_substr($name, 0, 128),
            'quantity' => $quantity,
            'quantityUnit' => 'pc',
            'unitPrice' => (int) round($totalNet / $quantity),
            'taxRate' => (int) round($taxRate * 100),
            'unitPriceIncVat' => (int) round($totalAmount / $quantity),
            'totalAmount' => $totalAmount,
            'totalVatAmount' => $totalVatAmount,
        ];
    }

    /**
     * Creates a shipping item from a CalculatedPrice object.
     */
    private function createShippingItem(CalculatedPrice $shippingCosts): array
    {
        $taxRate = 0;
        if ($shippingCosts->getCalculatedTaxes()->count() > 0) {
            $taxRate = $shippingCosts->getCalculatedTaxes()->first()->getTaxRate();
        }

        return $this->buildLine(
            'shipping',
            'Shipping',
            1,
            $shippingCosts->getTotalPrice(),
            $shippingCosts->getCalculatedTaxes()->getAmount(),
            $taxRate,
            'shipping_fee'
        );
    }

    /**
     * Fallback method for creating a shipping item from a plain float amount.
     * Used when the PaymentHandler passes a float instead of a CalculatedPrice.
     */
    private function createShippingItemFromFloat(float $amount): array
    {
        return [
            'productType' => 'shipping_fee',
            'reference' => 'shipping',
            'name' => 'Shipping',
            'quantity' => 1,
            'quantityUnit' => 'pc',
            'unitPrice' => (int) round($amount * 100),
            'taxRate' => 0,
            'totalAmount' => (int) round($amount * 100),
            'totalVatAmount' => 0,
        ];
    }

    /**
     * Calculates order totals (incl. and excl. VAT) from Briqpay-formatted items.
     *
     * @param array $briqpayItems Array of Briqpay cart items.
     *
     * @return array{amountIncVat: int, amountExVat: int}
     */
    public function calculateTotals(array $briqpayItems): array
    {
        $totalAmountIncVat = 0;
        $totalAmountExVat = 0;

        foreach ($briqpayItems as $item) {
            // Sum what each line actually says, rather than recomputing it from
            // the unit price: a line whose total came from Shopware (shipping,
            // and now every line) would otherwise be counted as a different
            // amount than the line itself declares, leaving the order total and
            // its own cart disagreeing inside a single payload. Confirmed live:
            // the cart summed to 940.54 while the order total said 940.53.
            if (isset($item['totalAmount'])) {
                $lineIncVat = (int) $item['totalAmount'];
                $lineExVat = $lineIncVat - (int) ($item['totalVatAmount'] ?? 0);
            } else {
                // A caller that only supplied unit prices (mapCaptureItems'
                // input shape, and the unit tests') still gets the old derivation.
                $lineExVat = (int) ($item['unitPrice'] * $item['quantity']);
                $lineIncVat = (int) round($lineExVat * (1 + ($item['taxRate'] / 10000)));
            }

            $totalAmountExVat += $lineExVat;
            $totalAmountIncVat += $lineIncVat;
        }

        return ['amountIncVat' => $totalAmountIncVat, 'amountExVat' => $totalAmountExVat];
    }

    /**
     * Maps a Shopware customer address to the Briqpay address format.
     *
     * @param CustomerAddressEntity|null $address
     * @param string|null                $email
     *
     * @return array Briqpay address data.
     */
    public function mapAddress(?CustomerAddressEntity $address, ?string $email): array
    {
        if (!$address) {
            return [];
        }

        $data = [
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'streetAddress' => $address->getStreet(),
            'city' => $address->getCity(),
            'zip' => $address->getZipcode(),
            'country' => $address->getCountry()?->getIso(),
            'email' => $email,
        ];

        if (!empty($address->getPhoneNumber())) {
            $data['phoneNumber'] = $address->getPhoneNumber();
        }

        return $data;
    }

    /**
     * Guards against silently sending wrong amounts for currencies whose minor-unit
     * precision isn't 2 decimals (e.g. JPY/ISK/KRW at 0 decimals, or KWD/BHD/TND at 3).
     *
     * Every monetary value in this class is hardcoded to multiply by 100 to reach
     * Briqpay's minor-unit format, which assumes 2 decimals. For any other precision
     * that would silently be 100x too large or 10x too small. Rather than guessing at
     * a conversion Briqpay's API may not even support, we fail fast — mirroring the
     * WooCommerce integration, which hides the payment method entirely for such
     * currencies unless a merchant explicitly opts in.
     *
     * @throws \RuntimeException if the sales channel currency uses unsupported precision.
     */
    public function assertSupportedCurrencyPrecision(\Shopware\Core\System\SalesChannel\SalesChannelContext $context): void
    {
        $decimals = $context->getCurrency()->getTotalRounding()->getDecimals();

        if ($decimals !== 2) {
            throw new \RuntimeException(sprintf(
                'Briqpay only supports currencies with 2 decimal places; sales channel currency "%s" uses %d.',
                $context->getCurrency()->getIsoCode(),
                $decimals
            ));
        }
    }

    /**
     * Resolves the plugin version, cached after first lookup.
     */
    private function getPluginVersion(): string
    {
        if ($this->pluginVersion !== null) {
            return $this->pluginVersion;
        }

        try {
            $plugin = $this->pluginService->getPluginByName('BriqpayPayments', Context::createDefaultContext());
            $this->pluginVersion = $plugin->getVersion();
        } catch (\Exception $e) {
            $this->pluginVersion = 'unknown';
        }

        return $this->pluginVersion;
    }

    /**
     * Returns the User-Agent string for Briqpay API requests.
     */
    public function getUserAgent(): string
    {
        return sprintf('Briqpay Shopware - %s - %s', $this->getPluginVersion(), $this->shopwareVersion);
    }

    /**
     * Returns the standard headers for Briqpay API requests (Basic Auth + User-Agent).
     */
    public function getApiHeader(): array
    {
        $clientId = $this->configService->get('BriqpayPayments.config.clientId');
        $secret = $this->configService->get('BriqpayPayments.config.clientSecret');

        return [
            'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $secret),
            'Content-Type' => 'application/json',
            'User-Agent' => $this->getUserAgent(),
        ];
    }

    /**
     * Returns the Briqpay API base URL based on test mode configuration.
     */
    public function getBriqpayBaseUrl(): string
    {
        return $this->configService->get('BriqpayPayments.config.testMode')
            ? 'https://playground-api.briqpay.com' : 'https://api.briqpay.com';
    }
}
