<?php

namespace App\Entity;

use App\Repository\ExportJobEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Évènement de l'historique d'un export (étape commencée, échec, téléchargement, alerte,
 * consultation par un administrateur…). Conservé avec l'export, après suppression du fichier.
 * Jamais de donnée médicale : un type, une étape, un message générique et des compteurs.
 */
#[ORM\Entity(repositoryClass: ExportJobEventRepository::class)]
#[ORM\Table(name: 'export_job_event')]
class ExportJobEvent
{
    public const REQUESTED = 'requested';
    public const REUSED = 'reused';
    public const STARTED = 'started';
    public const STEP = 'step';
    public const READY = 'ready';
    public const FAILED = 'failed';
    public const CANCEL_REQUESTED = 'cancel_requested';
    public const CANCELLED = 'cancelled';
    public const STALLED = 'stalled';
    public const REDELIVERED = 'redelivered';
    public const DOWNLOAD_STARTED = 'download_started';
    public const DOWNLOAD_SERVED = 'download_served';
    public const DOWNLOAD_INTERRUPTED = 'download_interrupted';
    public const DOWNLOAD_REFUSED = 'download_refused';
    public const DOWNLOAD_CONFIRMED = 'download_confirmed';
    public const DOWNLOAD_SIZE_MISMATCH = 'download_size_mismatch';
    public const NOT_DOWNLOADED = 'not_downloaded';
    public const FILE_DELETED = 'file_deleted';
    public const EXPIRED = 'expired';
    public const ALERT_SENT = 'alert_sent';
    public const ALERT_FAILED = 'alert_failed';
    public const ALERT_SUPPRESSED = 'alert_suppressed';
    public const ALERT_DISABLED = 'alert_disabled';
    public const ADMIN_VIEWED = 'admin_viewed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExportJob::class)]
    #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
    private ExportJob $job;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $type;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $step = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $message = null;

    /** Compteurs et identifiants techniques (ex. {"total": 1344}, {"adminId": 3}). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    public function __construct(ExportJob $job, string $type, \DateTimeImmutable $occurredAt, ?string $step = null, ?string $message = null, ?array $context = null)
    {
        $this->job = $job;
        $this->type = $type;
        $this->occurredAt = $occurredAt;
        $this->step = $step;
        $this->message = $message !== null ? mb_substr($message, 0, 500) : null;
        $this->context = $context;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): ExportJob
    {
        return $this->job;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStep(): ?string
    {
        return $this->step;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }
}
