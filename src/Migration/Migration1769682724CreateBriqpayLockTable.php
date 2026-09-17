<?php declare(strict_types=1);

namespace Briqpay\Payments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Backs Briqpay\Payments\Service\BriqpayLockService — a lightweight DB-based
 * mutex/idempotency-claim primitive used to guard capture/refund/cancel actions
 * and webhook processing against concurrent/duplicate execution.
 *
 * A plain table with a PRIMARY KEY on `lock_key` is used instead of Symfony's
 * Lock component so this works out of the box on any Shopware installation
 * regardless of whether framework.lock is configured with a real store.
 */
class Migration1769682724CreateBriqpayLockTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1769682724;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `briqpay_lock` (
                `lock_key` VARCHAR(191) NOT NULL,
                `expires_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`lock_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
