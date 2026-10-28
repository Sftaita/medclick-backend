<?php

namespace App\Export\Monitoring;

use App\Export\Monitoring\Message\MonitorExports;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MonitorExportsHandler
{
    public function __construct(private ExportMonitor $monitor, private ExportWorkerWatch $workerWatch, private LoggerInterface $logger)
    {
    }

    /** Exécuté dans le worker (planification) : signe de vie du worker, puis surveillance. */
    public function __invoke(MonitorExports $message): void
    {
        $this->workerWatch->heartbeat();
        $report = $this->monitor->runExclusive(ExportWorkerWatch::BY_WORKER);
        if ($report !== null && array_sum($report) > 0) {
            $this->logger->info('[export] Surveillance', $report);
        }
    }
}
