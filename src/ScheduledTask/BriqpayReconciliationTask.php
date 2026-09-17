<?php declare(strict_types=1);

namespace Briqpay\Payments\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Periodically fails Briqpay order transactions stuck in 'open' past a stale
 * threshold — see BriqpayReconciliationService for the reasoning on scope.
 */
class BriqpayReconciliationTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'briqpay.reconciliation';
    }

    public static function getDefaultInterval(): int
    {
        return self::MINUTELY * 30;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
