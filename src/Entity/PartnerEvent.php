<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Impression réellement vue (50 % visible pendant 1 s) ou clic sur un emplacement partenaire.
 * Mêmes règles que les campagnes (MarketingView) : aucune donnée nominative, empreinte HMAC
 * quotidienne du visiteur effacée après 24 h, doublons ignorés pendant 30 minutes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'partner_event')]
#[ORM\Index(name: 'idx_partner_event_stats', columns: ['partner_id', 'kind', 'viewed_at'])]
#[ORM\Index(name: 'idx_partner_event_date', columns: ['viewed_at'])]
class PartnerEvent
{
    public const KIND_IMPRESSION = 'impression';
    public const KIND_CLICK = 'click';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Partner::class)]
    #[ORM\JoinColumn(name: 'partner_id', nullable: false, onDelete: 'CASCADE')]
    private Partner $partner;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $placement;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $kind;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $viewerHash;

    /** Heure de Belgique (comme marketing_view.viewed_at). */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $viewedAt;

    public function __construct(Partner $partner, string $placement, string $kind, ?string $viewerHash, \DateTime $viewedAt)
    {
        $this->partner = $partner;
        $this->placement = $placement;
        $this->kind = $kind;
        $this->viewerHash = $viewerHash;
        $this->viewedAt = $viewedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function getPlacement(): string
    {
        return $this->placement;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
