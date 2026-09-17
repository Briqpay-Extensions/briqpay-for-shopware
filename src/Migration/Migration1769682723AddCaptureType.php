<?php declare(strict_types=1);

namespace Briqpay\Payments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds type column to briqpay_capture table.
 * Used to differentiate between capture and refund records in the UI.
 */
class Migration1769682723AddCaptureType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1769682723;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchAllAssociative('SHOW COLUMNS FROM `briqpay_capture`');
        $columnNames = array_column($columns, 'Field');

        if (!in_array('type', $columnNames, true)) {
            $connection->executeStatement(
                'ALTER TABLE `briqpay_capture` ADD COLUMN `type` VARCHAR(255) NULL AFTER `is_final`'
            );

            // Populate existing records
            $connection->executeStatement(
                "UPDATE `briqpay_capture` SET `type` = 'refund' WHERE `parent_capture_id` IS NOT NULL"
            );
            $connection->executeStatement(
                "UPDATE `briqpay_capture` SET `type` = 'capture' WHERE `parent_capture_id` IS NULL"
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
