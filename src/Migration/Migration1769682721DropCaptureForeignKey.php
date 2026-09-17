<?php declare(strict_types=1);

namespace Briqpay\Payments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Drops the ON DELETE CASCADE foreign key on briqpay_capture.order_transaction_id.
 *
 * Shopware's admin versioning system deletes and recreates order_transaction rows
 * when merging order changes. The CASCADE constraint was causing our capture
 * records to be silently deleted during these operations.
 */
class Migration1769682721DropCaptureForeignKey extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1769682721;
    }

    public function update(Connection $connection): void
    {
        // Check if the foreign key exists before attempting to drop
        $fks = $connection->fetchAllAssociative(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'briqpay_capture'
             AND REFERENCED_TABLE_NAME = 'order_transaction'"
        );

        foreach ($fks as $fk) {
            $name = $fk['CONSTRAINT_NAME'];
            $connection->executeStatement(
                "ALTER TABLE `briqpay_capture` DROP FOREIGN KEY `$name`"
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
