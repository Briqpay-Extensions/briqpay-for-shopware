<?php declare(strict_types=1);

namespace Briqpay\Payments;

use Briqpay\Payments\Payment\BriqpayPaymentHandler;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class BriqpayPayments extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->addPaymentMethod($installContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->addPaymentMethod($activateContext->getContext());
        $this->setPaymentMethodActive(true, $activateContext->getContext());
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setPaymentMethodActive(false, $deactivateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->setPaymentMethodActive(false, $uninstallContext->getContext());

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // The briqpay_capture table's FK on order_transaction_id was deliberately
        // dropped in Migration1769682721DropCaptureForeignKey to avoid Shopware's
        // order-versioning cascading deletes into it. That means it is NOT cleaned
        // up automatically anymore, so we drop it explicitly here when the merchant
        // has not asked to keep plugin data.
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `briqpay_capture`');
    }

    private function addPaymentMethod(Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId();

        /** @var EntityRepository $paymentRepo */
        $paymentRepo = $this->container->get('payment_method.repository');

        /** @var EntityRepository $pluginRepo */
        $pluginRepo = $this->container->get('plugin.repository');

        // Find the plugin ID for association
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'BriqpayPayments'));
        $pluginId = $pluginRepo->searchIds($criteria, $context)->firstId();

        $paymentData = [
            'handlerIdentifier' => BriqpayPaymentHandler::class,
            'name' => 'Briqpay',
            'technicalName' => 'payment_briqpay',
            'active' => true,
            'afterOrderEnabled' => true,
            'pluginId' => $pluginId,
            'description' => 'Pay with Briqpay',
        ];

        if ($paymentMethodId) {
            $paymentData['id'] = $paymentMethodId;
        }

        $paymentRepo->upsert([$paymentData], $context);

        $this->setPaymentMethodToSalesChannels($context);
    }

    /**
     * Toggles the payment method active/inactive state.
     */
    private function setPaymentMethodActive(bool $active, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId();
        if (!$paymentMethodId) {
            return;
        }

        /** @var EntityRepository $paymentRepo */
        $paymentRepo = $this->container->get('payment_method.repository');
        $paymentRepo->update([
            [
                'id' => $paymentMethodId,
                'active' => $active,
            ],
        ], $context);
    }

    private function setPaymentMethodToSalesChannels(Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId();
        if (!$paymentMethodId) {
            return;
        }

        /** @var EntityRepository $salesChannelRepo */
        $salesChannelRepo = $this->container->get('sales_channel.repository');
        $salesChannels = $salesChannelRepo->searchIds(new Criteria(), $context);

        $updateData = [];
        foreach ($salesChannels->getIds() as $salesChannelId) {
            $updateData[] = [
                'id' => $salesChannelId,
                'paymentMethods' => [
                    ['id' => $paymentMethodId],
                ],
            ];
        }

        $salesChannelRepo->update($updateData, $context);
    }

    private function getPaymentMethodId(): ?string
    {
        /** @var EntityRepository $paymentRepo */
        $paymentRepo = $this->container->get('payment_method.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', BriqpayPaymentHandler::class));

        return $paymentRepo->searchIds($criteria, Context::createDefaultContext())->firstId();
    }
}
