<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerPlacementAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Affectation d'une firme (Partner) à UN emplacement partenaire, pour une période (jours
 * inclus, Europe/Brussels). C'est la seule source d'affichage des six emplacements : aucune
 * firme (BOSI compris) n'est affichée sans affectation publiée.
 *
 * - Emplacement exclusif (tous sauf la page « Nos partenaires ») : une seule affectation
 *   publiée à la fois, les périodes ne se chevauchent pas (contrôlé à la publication).
 * - Emplacement partagé (page « Nos partenaires ») : plusieurs firmes en même temps.
 * - Firme null : emplacement volontairement masqué sur la période.
 */
#[ORM\Entity(repositoryClass: PartnerPlacementAssignmentRepository::class)]
#[ORM\Table(name: 'partner_placement_assignment')]
#[ORM\Index(name: 'idx_partner_placement_period', columns: ['placement', 'status', 'starts_on', 'ends_on'])]
class PartnerPlacementAssignment
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const WITHDRAWN = 'withdrawn';

    public const LOGIN_FALLBACK = 'login_fallback';
    public const DASHBOARD_PARTNER = 'dashboard_partner';
    public const SIDEBAR = 'sidebar';
    public const MOBILE_MENU = 'mobile_menu';
    public const PARTNERS_PAGE = 'partners_page';
    public const PUBLIC_HOME = 'public_home';

    public const PLACEMENTS = [
        self::LOGIN_FALLBACK, self::DASHBOARD_PARTNER, self::SIDEBAR,
        self::MOBILE_MENU, self::PARTNERS_PAGE, self::PUBLIC_HOME,
    ];

    /** Emplacements où plusieurs firmes peuvent être affichées en même temps. */
    public const SHARED = [self::PARTNERS_PAGE];

    public const LABELS = [
        self::LOGIN_FALLBACK => 'Connexion (sans publicité)',
        self::DASHBOARD_PARTNER => 'Partenaire du mois (tableau de bord)',
        self::SIDEBAR => 'Barre latérale',
        self::MOBILE_MENU => 'Menu mobile',
        self::PARTNERS_PAGE => 'Page « Nos partenaires »',
        self::PUBLIC_HOME => 'Accueil public',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(name: 'partner_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Partner $partner = null;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $placement = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

    /** Dernier jour d'affichage (inclus) ; null : sans date de fin. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::DRAFT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public static function isShared(string $placement): bool
    {
        return in_array($placement, self::SHARED, true);
    }

    /** draft, scheduled, live, ended ou withdrawn (jours comparés en AAAA-MM-JJ). */
    public function stateOn(string $day): string
    {
        $starts = $this->startsOn?->format('Y-m-d');
        $ends = $this->endsOn?->format('Y-m-d');

        return match (true) {
            $this->status === self::DRAFT => 'draft',
            $this->status === self::WITHDRAWN => 'withdrawn',
            $starts !== null && $starts > $day => 'scheduled',
            $ends !== null && $ends < $day => 'ended',
            default => 'live',
        };
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    public function getPlacement(): string
    {
        return $this->placement;
    }

    public function setPlacement(string $placement): self
    {
        $this->placement = $placement;

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

    public function publish(): self
    {
        $this->status = self::PUBLISHED;
        $this->publishedAt ??= new \DateTimeImmutable();
        $this->withdrawnAt = null;

        return $this->touch();
    }

    public function withdraw(): self
    {
        $this->status = self::WITHDRAWN;
        $this->withdrawnAt = new \DateTimeImmutable();

        return $this->touch();
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
