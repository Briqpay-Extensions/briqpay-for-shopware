<?php declare(strict_types=1);

namespace Briqpay\Payments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds parent_capture_id column to briqpay_capture table.
 * This links refund records back to the Briqpay capture ID they refund against.
 */
class Migration1769682722AddRefundCaptureLink extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1769682722;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchAllAssociative('SHOW COLUMNS FROM `briqpay_capture`');
        $columnNames = array_column($columns, 'Field');

        if (!in_array('parent_capture_id', $columnNames, true)) {
            $connection->executeStatement(
                'ALTER TABLE `briqpay_capture` ADD COLUMN `parent_capture_id` VARCHAR(255) NULL AFTER `briqpay_capture_id`'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
