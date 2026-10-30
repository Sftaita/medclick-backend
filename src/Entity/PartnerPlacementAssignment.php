<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartnerPlacementAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Affectation d'une fiche partenaire à UN emplacement. Le profil Partner reste indépendant
 * de la période : BOSI peut continuer sur un écran pendant qu'une autre firme occupe un autre.
 * Un partenaire null est une suppression explicite de cet emplacement sur la période indiquée.
 */
#[ORM\Entity(repositoryClass: PartnerPlacementAssignmentRepository::class)]
#[ORM\Table(name: 'partner_placement_assignment')]
#[ORM\Index(name: 'idx_partner_placement_period', columns: ['placement', 'status', 'starts_on', 'ends_on'])]
class PartnerPlacementAssignment
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const WITHDRAWN = 'withdrawn';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Partner $partner = null;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $placement = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

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

    public function getId(): ?int { return $this->id; }
    public function getPartner(): ?Partner { return $this->partner; }
    public function setPartner(?Partner $partner): self { $this->partner = $partner; return $this; }
    public function getPlacement(): string { return $this->placement; }
    public function setPlacement(string $placement): self { $this->placement = $placement; return $this; }
    public function getStartsOn(): ?\DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(?\DateTimeImmutable $startsOn): self { $this->startsOn = $startsOn; return $this; }
    public function getEndsOn(): ?\DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(?\DateTimeImmutable $endsOn): self { $this->endsOn = $endsOn; return $this; }
    public function getStatus(): string { return $this->status; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getWithdrawnAt(): ?\DateTimeImmutable { return $this->withdrawnAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function publish(): void
    {
        $this->status = self::PUBLISHED;
        $this->publishedAt ??= new \DateTimeImmutable();
        $this->withdrawnAt = null;
        $this->touch();
    }

    public function withdraw(): void
    {
        $this->status = self::WITHDRAWN;
        $this->withdrawnAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
