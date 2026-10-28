<?php

namespace App\Entity;

use App\Repository\ExportJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'export Excel : tâche de génération ET historique permanent (conservé après la
 * suppression du fichier, jusqu'à la durée de rétention EXPORT_HISTORY_RETENTION_DAYS).
 *
 * Aucune donnée médicale : seuls le type d'export, l'année demandée (id et numéro), des compteurs,
 * des dates et des codes d'erreur sont stockés. Le fichier lui-même vit dans le dossier des exports
 * (var/exports), sous un nom aléatoire, jusqu'à expiresAt.
 *
 * Écrit par App\Export\Job\ExportJobManager (et en DBAL par le suivi de progression du worker).
 * Lu par l'utilisateur (/api/exports/*, ses propres exports uniquement) et l'admin
 * (/api/admin/exports, sans accès aux fichiers).
 */
#[ORM\Entity(repositoryClass: ExportJobRepository::class)]
#[ORM\Table(name: 'export_job')]
#[ORM\Index(name: 'idx_export_job_user', columns: ['user_id', 'requested_at'])]
#[ORM\Index(name: 'idx_export_job_status', columns: ['status', 'requested_at'])]
#[ORM\Index(name: 'idx_export_job_requested', columns: ['requested_at'])]
class ExportJob
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_DOWNLOADED = 'downloaded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_READY, self::STATUS_DOWNLOADED,
        self::STATUS_FAILED, self::STATUS_EXPIRED, self::STATUS_CANCELLED];
    /** Statuts d'une génération pas encore terminée. */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    public const ALERT_NONE = 'none';
    public const ALERT_DISABLED = 'disabled';
    public const ALERT_SENT = 'sent';
    public const ALERT_FAILED = 'failed';
    public const ALERT_SUPPRESSED = 'suppressed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Identifiant public (UUID v4) : seul identifiant exposé (URL, interface, e-mails). */
    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $publicId;

    /** Identifiant de corrélation des journaux techniques (préfixe « export » de chaque ligne de log). */
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $correlationId;

    /** Demandeur (id seulement, comme error_log : l'historique survit à la suppression du compte). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $type;

    /** Filtres appliqués (ex. {"yearId": 12, "yearOfFormation": "2"}), jamais de donnée médicale. */
    #[ORM\Column(type: Types::JSON)]
    private array $parameters = [];

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_PENDING;

    /** Étape en cours (App\Export\ExportStep) ; dernière étape atteinte une fois terminé. */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $step = 'queued';

    /** Éléments traités / total de l'étape en cours (null si l'étape n'a pas de total connu). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $processed = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $total = null;

    /** Nombre d'interventions de l'année exportée (connu après le chargement). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $rowCount = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Durée de génération (début → fin ou échec), en millisecondes. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    /** Dernier signe de vie du worker (mis à jour à chaque progression). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heartbeatAt = null;

    /** Prises en charge par un worker (> 1 : message redélivré après une interruption). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $attempts = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $cancelRequested = false;

    /** Nom du fichier dans le dossier des exports (aléatoire, sans donnée personnelle). */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $fileSha256 = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fileDeletedAt = null;

    /** Requêtes de téléchargement reçues (réussies ou non). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $downloadAttempts = 0;

    /** Premier envoi complet observé côté serveur (tous les octets écrits, connexion ouverte). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstServedAt = null;

    /** Premier téléchargement CONFIRMÉ par le navigateur (fichier reçu en entier, taille vérifiée). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstDownloadedAt = null;

    /** Fichier prêt mais jamais téléchargé après EXPORT_NOT_DOWNLOADED_AFTER_MINUTES. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notDownloadedAt = null;

    /** Inclus dans un récapitulatif « exports non téléchargés » envoyé à l'administrateur. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notDownloadedAlertedAt = null;

    /** App\Export\ExportFailure::CATEGORIES */
    #[ORM\Column(type: Types::STRING, length: 30, nullable: true)]
    private ?string $errorCategory = null;

    /** Code technique : classe d'exception et emplacement, SQLSTATE (jamais de donnée). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $errorCode = null;

    /** Message lisible, générique par catégorie (jamais le message brut d'une exception). */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $errorStep = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $alertStatus = self::ALERT_NONE;

    /** Signature de l'incident (type, catégorie, étape, code) : une alerte par incident et par période. */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $alertFingerprint = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $alertAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $alertLastAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $alertSentAt = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $alertLastError = null;

    /** Export dont l'alerte couvre déjà cet incident (alerte supprimée comme doublon). */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $alertDuplicateOf = null;

    public function __construct(string $publicId, string $correlationId, int $userId, string $type, array $parameters, \DateTimeImmutable $requestedAt)
    {
        $this->publicId = $publicId;
        $this->correlationId = $correlationId;
        $this->userId = $userId;
        $this->type = $type;
        $this->parameters = $parameters;
        $this->requestedAt = $requestedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function setStep(string $step): self
    {
        $this->step = $step;

        return $this;
    }

    public function getProcessed(): ?int
    {
        return $this->processed;
    }

    public function setProcessed(?int $processed): self
    {
        $this->processed = $processed;

        return $this;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function setTotal(?int $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function setRowCount(?int $rowCount): self
    {
        $this->rowCount = $rowCount;

        return $this;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getHeartbeatAt(): ?\DateTimeImmutable
    {
        return $this->heartbeatAt;
    }

    public function setHeartbeatAt(?\DateTimeImmutable $heartbeatAt): self
    {
        $this->heartbeatAt = $heartbeatAt;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): self
    {
        ++$this->attempts;

        return $this;
    }

    public function isCancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    public function setCancelRequested(bool $cancelRequested): self
    {
        $this->cancelRequested = $cancelRequested;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getFileSha256(): ?string
    {
        return $this->fileSha256;
    }

    public function setFileSha256(?string $fileSha256): self
    {
        $this->fileSha256 = $fileSha256;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getFileDeletedAt(): ?\DateTimeImmutable
    {
        return $this->fileDeletedAt;
    }

    public function setFileDeletedAt(?\DateTimeImmutable $fileDeletedAt): self
    {
        $this->fileDeletedAt = $fileDeletedAt;

        return $this;
    }

    public function getDownloadAttempts(): int
    {
        return $this->downloadAttempts;
    }

    public function incrementDownloadAttempts(): self
    {
        ++$this->downloadAttempts;

        return $this;
    }

    public function getFirstServedAt(): ?\DateTimeImmutable
    {
        return $this->firstServedAt;
    }

    public function setFirstServedAt(?\DateTimeImmutable $firstServedAt): self
    {
        $this->firstServedAt = $firstServedAt;

        return $this;
    }

    public function getFirstDownloadedAt(): ?\DateTimeImmutable
    {
        return $this->firstDownloadedAt;
    }

    public function setFirstDownloadedAt(?\DateTimeImmutable $firstDownloadedAt): self
    {
        $this->firstDownloadedAt = $firstDownloadedAt;

        return $this;
    }

    public function getNotDownloadedAt(): ?\DateTimeImmutable
    {
        return $this->notDownloadedAt;
    }

    public function setNotDownloadedAt(?\DateTimeImmutable $notDownloadedAt): self
    {
        $this->notDownloadedAt = $notDownloadedAt;

        return $this;
    }

    public function getNotDownloadedAlertedAt(): ?\DateTimeImmutable
    {
        return $this->notDownloadedAlertedAt;
    }

    public function setNotDownloadedAlertedAt(?\DateTimeImmutable $at): self
    {
        $this->notDownloadedAlertedAt = $at;

        return $this;
    }

    public function getErrorCategory(): ?string
    {
        return $this->errorCategory;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorStep(): ?string
    {
        return $this->errorStep;
    }

    public function setError(string $category, ?string $code, string $message, ?string $step): self
    {
        $this->errorCategory = $category;
        $this->errorCode = $code !== null ? mb_substr($code, 0, 255) : null;
        $this->errorMessage = mb_substr($message, 0, 500);
        $this->errorStep = $step;

        return $this;
    }

    public function getAlertStatus(): string
    {
        return $this->alertStatus;
    }

    public function setAlertStatus(string $alertStatus): self
    {
        $this->alertStatus = $alertStatus;

        return $this;
    }

    public function getAlertFingerprint(): ?string
    {
        return $this->alertFingerprint;
    }

    public function setAlertFingerprint(?string $fingerprint): self
    {
        $this->alertFingerprint = $fingerprint;

        return $this;
    }

    public function getAlertAttempts(): int
    {
        return $this->alertAttempts;
    }

    public function incrementAlertAttempts(): self
    {
        ++$this->alertAttempts;

        return $this;
    }

    public function getAlertLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->alertLastAttemptAt;
    }

    public function setAlertLastAttemptAt(?\DateTimeImmutable $at): self
    {
        $this->alertLastAttemptAt = $at;

        return $this;
    }

    public function getAlertSentAt(): ?\DateTimeImmutable
    {
        return $this->alertSentAt;
    }

    public function setAlertSentAt(?\DateTimeImmutable $at): self
    {
        $this->alertSentAt = $at;

        return $this;
    }

    public function getAlertLastError(): ?string
    {
        return $this->alertLastError;
    }

    public function setAlertLastError(?string $error): self
    {
        $this->alertLastError = $error !== null ? mb_substr($error, 0, 500) : null;

        return $this;
    }

    public function getAlertDuplicateOf(): ?string
    {
        return $this->alertDuplicateOf;
    }

    public function setAlertDuplicateOf(?string $publicId): self
    {
        $this->alertDuplicateOf = $publicId;

        return $this;
    }

    /** Identifiant de diagnostic court, communiqué à l'utilisateur et dans l'alerte. */
    public function getDiagnosticId(): string
    {
        return 'EXP-' . strtoupper(substr(str_replace('-', '', $this->publicId), 0, 8));
    }
}
