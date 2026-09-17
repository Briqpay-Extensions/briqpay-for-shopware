<?php declare(strict_types=1);

namespace Briqpay\Payments\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Writes Briqpay's own copy of the billing/shipping address back onto the
 * Shopware order, once the order exists.
 *
 * Today address data only flows Shopware -> Briqpay (see
 * BriqpayRequestFactory::mapAddress() / CheckoutSubscriber). This is a
 * one-directional top-up in the other direction for the cases where Briqpay's
 * own copy is more authoritative than what was in the cart — e.g. a PSP's
 * strong-auth step correcting a name, or (if company_lookup / a B2B hosted
 * flow that lets Briqpay collect the address itself is added later) company
 * data Shopware never had in the first place. It is intentionally
 * conservative: a field is only overwritten when Briqpay actually returned a
 * non-empty value for it, so an incomplete session payload can never blank
 * out data Shopware already had.
 */
class BriqpayAddressSyncService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderAddressRepository,
        private readonly EntityRepository $countryRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array $session Full Briqpay session response (as returned by BriqpaySessionService::getSession()).
     */
    public function syncFromSession(string $orderId, array $session, Context $context): void
    {
        $data = $session['data'] ?? [];
        $billing = $data['billing'] ?? null;
        $shipping = $data['shipping'] ?? null;

        if (empty($billing) && empty($shipping)) {
            return;
        }

        try {
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('billingAddress');
            $criteria->addAssociation('deliveries.shippingOrderAddress');
            $order = $this->orderRepository->search($criteria, $context)->first();

            if (!$order instanceof OrderEntity) {
                return;
            }

            if (!empty($billing)) {
                $this->syncAddress($order->getBillingAddressId(), $billing, $context);
            }

            if (!empty($shipping)) {
                $delivery = $order->getDeliveries()?->first();
                $shippingAddressId = $delivery?->getShippingOrderAddressId();
                if ($shippingAddressId) {
                    $this->syncAddress($shippingAddressId, $shipping, $context);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Briqpay: Could not sync order addresses from session', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array $briqpayAddress Shape matches BriqpayRequestFactory::mapAddress()'s output
     *                              (firstName, lastName, streetAddress, city, zip, country, phoneNumber).
     */
    private function syncAddress(string $addressId, array $briqpayAddress, Context $context): void
    {
        $update = ['id' => $addressId];

        $fieldMap = [
            'firstName' => 'firstName',
            'lastName' => 'lastName',
            'streetAddress' => 'street',
            'city' => 'city',
            'zip' => 'zipcode',
            'phoneNumber' => 'phoneNumber',
        ];

        foreach ($fieldMap as $briqpayField => $shopwareField) {
            if (!empty($briqpayAddress[$briqpayField])) {
                $update[$shopwareField] = $briqpayAddress[$briqpayField];
            }
        }

        if (!empty($briqpayAddress['country'])) {
            $countryId = $this->resolveCountryId((string) $briqpayAddress['country'], $context);
            if ($countryId) {
                $update['countryId'] = $countryId;
            }
        }

        // Nothing besides the id was set — skip the write entirely.
        if (count($update) <= 1) {
            return;
        }

        $this->orderAddressRepository->update([$update], $context);
    }

    private function resolveCountryId(string $isoCode, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', strtoupper($isoCode)));

        return $this->countryRepository->searchIds($criteria, $context)->firstId();
    }
}
