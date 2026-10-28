<?php

namespace App\Entity;

use App\Repository\MarketingViewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement publicitaire d'une campagne : impression réellement vue, clic, ou affichage
 * historique (« legacy_view » : avant novembre 2026, une ligne était créée à chaque appel de
 * GET /api/marketing/active, que la publicité soit vue ou non ; conservé, jamais compté comme
 * impression).
 *
 * Aucune donnée nominative : viewerHash est une empreinte HMAC qui change chaque jour (utilisée
 * seulement pour ignorer les doublons pendant 30 minutes), effacée après 24 h.
 */
#[ORM\Entity(repositoryClass: MarketingViewRepository::class)]
#[ORM\Index(name: 'idx_marketing_view_stats', columns: ['marketing_id', 'kind', 'viewed_at'])]
#[ORM\Index(name: 'idx_marketing_view_date', columns: ['viewed_at'])]
class MarketingView
{
    public const KIND_IMPRESSION = 'impression';
    public const KIND_CLICK = 'click';
    public const KIND_LEGACY = 'legacy_view';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: Marketing::class, inversedBy: 'viewedAt')]
    #[ORM\JoinColumn(nullable: false)]
    private $marketing;

    #[ORM\Column(type: 'datetime')]
    private $viewedAt;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::KIND_IMPRESSION])]
    private string $kind = self::KIND_IMPRESSION;

    #[ORM\Column(type: 'string', length: 30, options: ['default' => Marketing::PLACEMENT_LOGIN_INTERSTITIAL])]
    private string $placement = Marketing::PLACEMENT_LOGIN_INTERSTITIAL;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $viewerHash = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMarketing(): ?Marketing
    {
        return $this->marketing;
    }

    public function setMarketing(?Marketing $marketing): self
    {
        $this->marketing = $marketing;

        return $this;
    }

    public function getViewedAt(): ?\DateTimeInterface
    {
        return $this->viewedAt;
    }

    public function setViewedAt(\DateTimeInterface $viewedAt): self
    {
        $this->viewedAt = $viewedAt;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;

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

    public function getViewerHash(): ?string
    {
        return $this->viewerHash;
    }

    public function setViewerHash(?string $viewerHash): self
    {
        $this->viewerHash = $viewerHash;

        return $this;
    }
}
