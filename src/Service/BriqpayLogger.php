<?php declare(strict_types=1);

namespace Briqpay\Payments\Service;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The plugin's logger: Shopware's logger behind the "Verbose logging" switch.
 *
 * Warnings and errors always go through -- a merchant needs to see a failed
 * capture or an unreachable API whether or not they asked for detail. The
 * informational trail (webhook received, duplicate delivery ignored,
 * reconciliation summary, ...) is only written when verbose logging is on,
 * because on a busy shop it is noise that also carries order identifiers.
 */
class BriqpayLogger extends AbstractLogger
{
    private const CONFIG_KEY = 'BriqpayPayments.config.verboseLogging';

    /**
     * Levels that are dropped unless verbose logging is enabled.
     */
    private const VERBOSE_ONLY = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE];

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly SystemConfigService $configService
    ) {
    }

    /**
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (\in_array($level, self::VERBOSE_ONLY, true) && !$this->isVerbose()) {
            return;
        }

        $this->inner->log($level, $message, $context);
    }

    private function isVerbose(): bool
    {
        return (bool) $this->configService->get(self::CONFIG_KEY);
    }
}
