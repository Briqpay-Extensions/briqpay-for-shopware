<?php declare(strict_types=1);

namespace Briqpay\Payments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds the 'type' column to the briqpay_capture table for capture/refund discrimination.
 */
class Migration1769682720AddCaptureType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1769682720;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchAllAssociative('SHOW COLUMNS FROM `briqpay_capture`');
        $columnNames = array_column($columns, 'Field');

        if (!in_array('type', $columnNames, true)) {
            $connection->executeStatement(
                "ALTER TABLE `briqpay_capture` ADD COLUMN `type` VARCHAR(32) NOT NULL DEFAULT 'capture' AFTER `is_final`"
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
