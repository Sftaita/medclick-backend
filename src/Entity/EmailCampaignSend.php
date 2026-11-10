<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailCampaignSendRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un envoi d'une campagne e-mail à une adresse (docs/CAMPAGNES-EMAIL.md).
 *
 * Protection contre les envois multiples : un envoi de campagne porte une clé de déduplication
 * unique (campagne + adresse), posée AVANT l'envoi. Une deuxième exécution, même simultanée, ne
 * peut donc pas écrire à la même adresse. Les e-mails test n'ont pas de clé (répétables).
 *
 * Le jeton de désinscription est propre à chaque envoi : le lien ne contient jamais l'adresse.
 */
#[ORM\Entity(repositoryClass: EmailCampaignSendRepository::class)]
#[ORM\Table(name: 'email_campaign_send')]
#[ORM\Index(name: 'idx_email_campaign_send_campaign', columns: ['campaign', 'kind', 'status'])]
#[ORM\UniqueConstraint(name: 'uniq_email_campaign_send_dedup', columns: ['dedup_key'])]
#[ORM\UniqueConstraint(name: 'uniq_email_campaign_send_token', columns: ['unsubscribe_token'])]
class EmailCampaignSend
{
    public const KIND_TEST = 'test';
    public const KIND_CAMPAIGN = 'campaign';

    /** Réservé, envoi en cours (ou interrompu : jamais renvoyé automatiquement, délivrance inconnue). */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 60)]
    private string $campaign;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $kind;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    /** sha1(campagne + adresse) pour un envoi de campagne ; null pour un e-mail test. */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $dedupKey = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $unsubscribeToken;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $status = self::STATUS_PENDING;

    /** Classe et message de l'erreur d'envoi (jamais d'identifiant de connexion). */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** Administrateur qui a demandé l'e-mail test (null pour la campagne). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $requestedBy = null;

    public function __construct(string $campaign, string $kind, string $email, string $unsubscribeToken)
    {
        $this->campaign = $campaign;
        $this->kind = $kind;
        $this->email = $email;
        $this->unsubscribeToken = $unsubscribeToken;
        $this->createdAt = new \DateTimeImmutable();
        if ($kind === self::KIND_CAMPAIGN) {
            $this->dedupKey = self::dedupKeyFor($campaign, $email);
        }
    }

    public static function dedupKeyFor(string $campaign, string $email): string
    {
        return sha1($campaign . "\n" . mb_strtolower(trim($email)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): string
    {
        return $this->campaign;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDedupKey(): ?string
    {
        return $this->dedupKey;
    }

    public function getUnsubscribeToken(): string
    {
        return $this->unsubscribeToken;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getRequestedBy(): ?int
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?int $requestedBy): self
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function markSent(): self
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->error = null;

        return $this;
    }

    public function markFailed(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error = mb_substr($error, 0, 500);

        return $this;
    }

    /** Nouvelle tentative explicite d'un envoi en échec (commande, option --retry-failed). */
    public function retry(): self
    {
        $this->status = self::STATUS_PENDING;
        $this->error = null;

        return $this;
    }
}
