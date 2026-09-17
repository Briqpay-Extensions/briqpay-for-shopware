<?php declare(strict_types=1);

namespace Briqpay\Payments\ScheduledTask;

use Briqpay\Payments\Service\BriqpayReconciliationService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: BriqpayReconciliationTask::class)]
class BriqpayReconciliationTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly BriqpayReconciliationService $reconciliationService
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->reconciliationService->reconcileStaleOrders();
    }
}
