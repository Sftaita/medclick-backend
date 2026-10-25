<?php

namespace App\Entity;

use App\Repository\PartnerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Partenaire (sponsor) affiché dans l'application : écran de connexion, tableau de bord,
 * barre latérale et page « Nos partenaires ». Administration : /api/admin/partners.
 *
 * Statuts : brouillon (jamais affiché), publié (affiché pendant sa période : programmé avant
 * la date de début, en ligne pendant, terminé après), retiré (publication arrêtée à la main).
 * Un seul partenaire est affiché à la fois : les périodes des partenaires publiés ne se
 * chevauchent jamais (contrôlé à la publication). Le partenaire affiché est calculé à chaque
 * requête à partir de la date du jour (Europe/Brussels) : le passage de l'un à l'autre est
 * automatique, sans tâche planifiée.
 */
#[ORM\Entity(repositoryClass: PartnerRepository::class)]
#[ORM\Table(name: 'partner')]
#[ORM\Index(name: 'idx_partner_period', columns: ['status', 'starts_on', 'ends_on'])]
class Partner
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const MAX_COMMITMENTS = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $websiteUrl = null;

    #[ORM\Column(type: Types::STRING, length: 160, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $about = null;

    /** @var list<string> Engagements affichés (4 au plus). */
    #[ORM\Column(type: Types::JSON)]
    private array $commitments = [];

    #[ORM\ManyToOne(targetEntity: PartnerLogo::class)]
    #[ORM\JoinColumn(name: 'logo_id', nullable: true)]
    private ?PartnerLogo $logo = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

    /** Dernier jour d'affichage (inclus) ; null : sans date de fin. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * État vu par l'administration à une date donnée : draft, scheduled (publié, pas encore
     * commencé), live (affiché), ended (période terminée) ou withdrawn.
     */
    public function stateOn(\DateTimeImmutable $today): string
    {
        // Comparaison de jours (AAAA-MM-JJ), jamais d'instants : Doctrine relit une colonne DATE
        // dans le fuseau de PHP (UTC dans le conteneur), « aujourd'hui » est calculé à Bruxelles.
        $day = $today->format('Y-m-d');

        return match (true) {
            $this->status === self::STATUS_DRAFT => 'draft',
            $this->status === self::STATUS_WITHDRAWN => 'withdrawn',
            $this->startsOn !== null && $this->startsOn->format('Y-m-d') > $day => 'scheduled',
            $this->endsOn !== null && $this->endsOn->format('Y-m-d') < $day => 'ended',
            default => 'live',
        };
    }

    /** Copie du contenu (reprogrammation) : nouveau brouillon, sans dates. */
    public function copyAsDraft(): self
    {
        $copy = new self();
        $copy->name = $this->name;
        $copy->websiteUrl = $this->websiteUrl;
        $copy->tagline = $this->tagline;
        $copy->about = $this->about;
        $copy->commitments = $this->commitments;
        $copy->logo = $this->logo;

        return $copy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getWebsiteUrl(): ?string
    {
        return $this->websiteUrl;
    }

    public function setWebsiteUrl(?string $websiteUrl): self
    {
        $this->websiteUrl = $websiteUrl;

        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): self
    {
        $this->tagline = $tagline;

        return $this;
    }

    public function getAbout(): ?string
    {
        return $this->about;
    }

    public function setAbout(?string $about): self
    {
        $this->about = $about;

        return $this;
    }

    /** @return list<string> */
    public function getCommitments(): array
    {
        return $this->commitments;
    }

    /** @param list<string> $commitments */
    public function setCommitments(array $commitments): self
    {
        $this->commitments = array_values($commitments);

        return $this;
    }

    public function getLogo(): ?PartnerLogo
    {
        return $this->logo;
    }

    public function setLogo(?PartnerLogo $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): self
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getEndsOn(): ?\DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(?\DateTimeImmutable $endsOn): self
    {
        $this->endsOn = $endsOn;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function publish(): self
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            $this->status = self::STATUS_PUBLISHED;
            $this->publishedAt = new \DateTimeImmutable();
            $this->withdrawnAt = null;
        }

        return $this;
    }

    public function withdraw(): self
    {
        $this->status = self::STATUS_WITHDRAWN;
        $this->withdrawnAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getWithdrawnAt(): ?\DateTimeImmutable
    {
        return $this->withdrawnAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
