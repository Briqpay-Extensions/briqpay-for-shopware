<?php declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Briqpay Payments plugin's unit test suite.
 *
 * These are pure unit tests (all Shopware/Doctrine collaborators are mocked
 * with PHPUnit test doubles) so we only need the Composer autoloader —
 * no Shopware kernel boot, no database connection required.
 */

require_once __DIR__ . '/../vendor/autoload.php';
