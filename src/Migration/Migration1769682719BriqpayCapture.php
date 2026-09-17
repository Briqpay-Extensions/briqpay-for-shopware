<?php declare(strict_types=1);

namespace Briqpay\Payments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1769682719BriqpayCapture extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1769682719;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `briqpay_capture` (
                `id` BINARY(16) NOT NULL,
                `order_transaction_id` BINARY(16) NOT NULL,
                `briqpay_capture_id` VARCHAR(255) NOT NULL,
                `amount` DECIMAL(20,4) NOT NULL,
                `is_final` TINYINT(1) NOT NULL DEFAULT 0,
                `type` VARCHAR(32) NOT NULL DEFAULT \'capture\',
                `items` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.briqpay_capture.order_transaction_id` (`order_transaction_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        // Add type column if table already exists without it
        $columns = $connection->fetchAllAssociative('SHOW COLUMNS FROM `briqpay_capture`');
        $columnNames = array_column($columns, 'Field');
        if (!in_array('type', $columnNames, true)) {
            $connection->executeStatement(
                'ALTER TABLE `briqpay_capture` ADD COLUMN `type` VARCHAR(32) NOT NULL DEFAULT \'capture\' AFTER `is_final`'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
