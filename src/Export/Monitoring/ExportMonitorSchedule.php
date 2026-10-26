<?php

namespace App\Export\Monitoring;

use App\Export\Monitoring\Message\MonitorExports;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Surveillance des exports toutes les minutes, exécutée par le worker des exports lui-même
 * (« messenger:consume exports scheduler_exports_monitor ») : aucune tâche cron à installer sur le
 * serveur. Si le worker est arrêté, l'API applique quand même la détection des exports bloqués à
 * chaque consultation de l'état (ExportJobManager::checkLiveness).
 */
#[AsSchedule('exports_monitor')]
final class ExportMonitorSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('1 minute', new MonitorExports()));
    }
}
