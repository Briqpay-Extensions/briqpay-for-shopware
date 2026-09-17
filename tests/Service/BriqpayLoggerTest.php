<?php declare(strict_types=1);

namespace Briqpay\Payments\Tests\Service;

use Briqpay\Payments\Service\BriqpayLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * @covers \Briqpay\Payments\Service\BriqpayLogger
 */
class BriqpayLoggerTest extends TestCase
{
    public function testWarningsAndErrorsAlwaysPassThrough(): void
    {
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->exactly(2))->method('log');

        $logger = new BriqpayLogger($inner, $this->configWithVerbose(false));

        $logger->warning('Briqpay: something odd');
        $logger->error('Briqpay: something broke');
    }

    public function testInfoIsDroppedWhenVerboseLoggingIsOff(): void
    {
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->never())->method('log');

        $logger = new BriqpayLogger($inner, $this->configWithVerbose(false));

        $logger->info('Briqpay: Webhook received');
        $logger->debug('Briqpay: discovery source unreachable');
        $logger->notice('Briqpay: note');
    }

    public function testInfoPassesThroughWhenVerboseLoggingIsOn(): void
    {
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->once())
            ->method('log')
            ->with(LogLevel::INFO, 'Briqpay: Webhook received', ['sessionId' => 'abc']);

        $logger = new BriqpayLogger($inner, $this->configWithVerbose(true));

        $logger->info('Briqpay: Webhook received', ['sessionId' => 'abc']);
    }

    private function configWithVerbose(bool $verbose): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')
            ->with('BriqpayPayments.config.verboseLogging')
            ->willReturn($verbose);

        return $config;
    }
}
