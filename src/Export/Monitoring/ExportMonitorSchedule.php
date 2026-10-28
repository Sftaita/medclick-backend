<?php

namespace App\Export\Monitoring;

use App\Export\Monitoring\Message\MonitorExports;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Surveillance des exports toutes les minutes, exécutée par le worker des exports lui-même
 * (« messenger:consume exports scheduler_exports_monitor ») ; elle sert aussi de signe de vie du
 * worker. Si le worker est arrêté, le gardien planifié sur le serveur (app:exports:monitor
 * --watchdog, cron) prend le relais et signale son absence (ExportWorkerWatch).
 */
#[AsSchedule('exports_monitor')]
final class ExportMonitorSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('1 minute', new MonitorExports()));
    }
}
