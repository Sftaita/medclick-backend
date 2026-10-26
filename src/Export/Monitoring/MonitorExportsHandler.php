<?php

namespace App\Export\Monitoring;

use App\Export\Monitoring\Message\MonitorExports;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MonitorExportsHandler
{
    public function __construct(private ExportMonitor $monitor, private LoggerInterface $logger)
    {
    }

    public function __invoke(MonitorExports $message): void
    {
        $report = $this->monitor->run();
        if (array_sum($report) > 0) {
            $this->logger->info('[export] Surveillance', $report);
        }
    }
}
