<?php

namespace App\Entity;

use App\Repository\ErrorLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Erreur rencontrée par un utilisateur (API, console ou front), regroupée par empreinte :
 * une même erreur qui se répète incrémente "occurrences" au lieu de créer une ligne.
 *
 * Écrit par App\Service\ErrorRecorder (via DBAL, pour fonctionner même si l'EntityManager
 * est fermé par l'erreur elle-même). Lu par l'admin : /api/admin/errors.
 */
#[ORM\Entity(repositoryClass: ErrorLogRepository::class)]
#[ORM\Table(name: 'error_log')]
#[ORM\Index(name: 'idx_error_log_last_seen', columns: ['last_seen_at'])]
class ErrorLog
{
    public const SOURCE_API = 'api';
    public const SOURCE_CONSOLE = 'console';
    public const SOURCE_PWA = 'pwa';
    public const SOURCE_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** sha1 de la signature de l'erreur (source + type + emplacement). */
    #[ORM\Column(type: Types::STRING, length: 40, unique: true)]
    private string $fingerprint;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $source;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $line = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $trace = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $statusCode = null;

    /** Dernier utilisateur touché (id seulement, pas de relation : l'écriture ne doit jamais échouer). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $occurrences = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getSource(): string { return $this->source; }
    public function getMessage(): string { return $this->message; }
    public function getExceptionClass(): ?string { return $this->exceptionClass; }
    public function getFile(): ?string { return $this->file; }
    public function getLine(): ?int { return $this->line; }
    public function getTrace(): ?string { return $this->trace; }
    public function getMethod(): ?string { return $this->method; }
    public function getPath(): ?string { return $this->path; }
    public function getStatusCode(): ?int { return $this->statusCode; }
    public function getUserId(): ?int { return $this->userId; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getContext(): ?array { return $this->context; }
    public function getOccurrences(): int { return $this->occurrences; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    /** Représentation pour l'admin ; la trace et le contexte seulement en détail. */
    public function toArray(bool $withDetails = false): array
    {
        $data = [
            'id' => $this->id,
            'source' => $this->source,
            'message' => $this->message,
            'exceptionClass' => $this->exceptionClass,
            'location' => $this->file ? $this->file . ':' . $this->line : null,
            'method' => $this->method,
            'path' => $this->path,
            'statusCode' => $this->statusCode,
            'userId' => $this->userId,
            'occurrences' => $this->occurrences,
            'firstSeenAt' => $this->firstSeenAt->format(DATE_ATOM),
            'lastSeenAt' => $this->lastSeenAt->format(DATE_ATOM),
            'resolvedAt' => $this->resolvedAt?->format(DATE_ATOM),
        ];

        if ($withDetails) {
            $data['trace'] = $this->trace;
            $data['context'] = $this->context;
            $data['userAgent'] = $this->userAgent;
        }

        return $data;
    }
}
