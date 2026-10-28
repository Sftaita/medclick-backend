<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * État de la surveillance des exports (une seule ligne, id 1), partagé par l'API, le worker et
 * le gardien planifié sur le serveur (docs/EXPORTS.md) : dernier signe de vie du worker, absence
 * en cours et son alerte, dernière surveillance, bail empêchant deux surveillances simultanées.
 * Aucune donnée personnelle. Écrit par App\Export\Monitoring\ExportWorkerWatch (requêtes
 * conditionnelles), lu par l'administration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'export_monitor_state')]
class ExportMonitorState
{
    public const ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id = self::ID;

    /** Dernier signe de vie du worker (surveillance planifiée dans le worker, chaque minute). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $workerSeenAt = null;

    /** Début de l'absence en cours du worker (null : worker présent). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $workerAbsentSince = null;

    /** Alerte de l'absence en cours : none, sent, failed, covered (incident déjà signalé), disabled. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $workerAlertStatus = 'none';

    #[ORM\Column(type: Types::INTEGER)]
    private int $workerAlertAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $workerAlertLastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $workerAlertSentAt = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $workerAlertLastError = null;

    /** Dernière surveillance exécutée et par qui (worker, gardien, commande). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $lastRunBy = null;

    /** Dernier passage du gardien planifié sur le serveur (indépendant du worker). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastWatchdogAt = null;

    /** Bail de la surveillance en cours (une seule à la fois ; expire si le processus s'arrête). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $runLeaseUntil = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getWorkerSeenAt(): ?\DateTimeImmutable
    {
        return $this->workerSeenAt;
    }

    public function getWorkerAbsentSince(): ?\DateTimeImmutable
    {
        return $this->workerAbsentSince;
    }

    public function getWorkerAlertStatus(): string
    {
        return $this->workerAlertStatus;
    }

    public function getWorkerAlertAttempts(): int
    {
        return $this->workerAlertAttempts;
    }

    public function getWorkerAlertLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->workerAlertLastAttemptAt;
    }

    public function getWorkerAlertSentAt(): ?\DateTimeImmutable
    {
        return $this->workerAlertSentAt;
    }

    public function getWorkerAlertLastError(): ?string
    {
        return $this->workerAlertLastError;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastRunBy(): ?string
    {
        return $this->lastRunBy;
    }

    public function getLastWatchdogAt(): ?\DateTimeImmutable
    {
        return $this->lastWatchdogAt;
    }

    public function getRunLeaseUntil(): ?\DateTimeImmutable
    {
        return $this->runLeaseUntil;
    }
}
