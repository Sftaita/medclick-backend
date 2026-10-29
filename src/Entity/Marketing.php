<?php

namespace App\Entity;

use App\Repository\MarketingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarketingRepository::class)]
class Marketing
{
    public const DEFAULT_DURATION = 5;
    public const MIN_DURATION = 1;
    public const MAX_DURATION = 60;

    // Emplacements vendus séparément. « login_interstitial » = publicité plein écran à la
    // connexion (format historique, conservé pour les campagnes existantes).
    public const PLACEMENT_LOGIN = 'login';
    public const PLACEMENT_DASHBOARD_DESKTOP = 'dashboard_desktop';
    public const PLACEMENT_DASHBOARD_MOBILE = 'dashboard_mobile';
    public const PLACEMENT_LOGIN_INTERSTITIAL = 'login_interstitial';
    public const PLACEMENTS = [self::PLACEMENT_LOGIN, self::PLACEMENT_DASHBOARD_DESKTOP, self::PLACEMENT_DASHBOARD_MOBILE, self::PLACEMENT_LOGIN_INTERSTITIAL];
    /** Emplacements en carte (visuel bannière), par opposition au plein écran. */
    public const CARD_PLACEMENTS = [self::PLACEMENT_LOGIN, self::PLACEMENT_DASHBOARD_DESKTOP, self::PLACEMENT_DASHBOARD_MOBILE];

    // Offres commerciales (libellé des rapports ; les emplacements restent choisis librement).
    public const OFFERS = ['premium', 'login', 'secondary'];

    public const MIN_PRIORITY = 1;
    public const MAX_PRIORITY = 10;
    public const DEFAULT_PRIORITY = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 400)]
    private $campaign_name;

    #[ORM\Column(type: 'string', length: 255)]
    private $status;

    #[ORM\Column(type: 'date')]
    private $start_date;

    #[ORM\Column(type: 'date', nullable: true)]
    private $end_date;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private $views = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private $clicks = 0;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private $smartphone_format;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private $tablet_portrait_format;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private $tablet_landscape_format;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private $screen_14_inch_format;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private $large_screen_format;

    #[ORM\OneToMany(targetEntity: MarketingView::class, mappedBy: 'marketing')]
    private $viewedAt;

    /** Durée d'affichage à la connexion, en secondes (MIN_DURATION à MAX_DURATION). */
    #[ORM\Column(type: 'integer', options: ['default' => 5])]
    private int $duration = self::DEFAULT_DURATION;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private $redirectUrl;

    /** Annonceur (regroupement des statistiques et des rapports). */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $sponsorName = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $offer = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'string', length: 280, nullable: true)]
    private ?string $description = null;

    /** Logo : nom de fichier d'un logo envoyé comme ceux des partenaires (<sha256>.png|svg). */
    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $logo = null;

    /** Visuel bannière ordinateur (16:9) : URL d'un visuel importé ou URL http(s). */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $bannerDesktop = null;

    /** Visuel bannière mobile et connexion (2:1). */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $bannerMobile = null;

    /** Bouton « Découvrir le partenaire » sous le visuel. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $showCta = false;

    /** @var list<string> sous-ensemble de PLACEMENTS */
    #[ORM\Column(type: 'json')]
    private array $placements = [self::PLACEMENT_LOGIN_INTERSTITIAL];

    /** Priorité commerciale (1 à 10) : ordre et poids dans la rotation. */
    #[ORM\Column(type: 'smallint', options: ['default' => 5])]
    private int $priority = self::DEFAULT_PRIORITY;

    /** Exclusive : seule campagne affichée sur ses emplacements pendant sa période. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $exclusive = false;

    public function __construct()
    {
        $this->viewedAt = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaignName(): ?string
    {
        return $this->campaign_name;
    }

    public function setCampaignName(string $campaign_name): self
    {
        $this->campaign_name = $campaign_name;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->start_date;
    }

    public function setStartDate(\DateTimeInterface $start_date): self
    {
        $this->start_date = $start_date;

        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->end_date;
    }

    public function setEndDate(?\DateTimeInterface $end_date): self
    {
        $this->end_date = $end_date;

        return $this;
    }

    public function getViews(): ?int
    {
        return $this->views;
    }

    public function setViews(int $views): self
    {
        $this->views = $views;

        return $this;
    }

    public function getClicks(): ?int
    {
        return $this->clicks;
    }

    public function setClicks(int $clicks): self
    {
        $this->clicks = $clicks;

        return $this;
    }

    public function getSmartphoneFormat(): ?string
    {
        return $this->smartphone_format;
    }

    public function setSmartphoneFormat(?string $smartphone_format): self
    {
        $this->smartphone_format = $smartphone_format;

        return $this;
    }

    public function getTabletPortraitFormat(): ?string
    {
        return $this->tablet_portrait_format;
    }

    public function setTabletPortraitFormat(?string $tablet_portrait_format): self
    {
        $this->tablet_portrait_format = $tablet_portrait_format;

        return $this;
    }

    public function getTabletLandscapeFormat(): ?string
    {
        return $this->tablet_landscape_format;
    }

    public function setTabletLandscapeFormat(?string $tablet_landscape_format): self
    {
        $this->tablet_landscape_format = $tablet_landscape_format;

        return $this;
    }

    public function getScreen14InchFormat(): ?string
    {
        return $this->screen_14_inch_format;
    }

    public function setScreen14InchFormat(?string $screen_14_inch_format): self
    {
        $this->screen_14_inch_format = $screen_14_inch_format;

        return $this;
    }

    public function getLargeScreenFormat(): ?string
    {
        return $this->large_screen_format;
    }

    public function setLargeScreenFormat(?string $large_screen_format): self
    {
        $this->large_screen_format = $large_screen_format;

        return $this;
    }

    /**
     * @return Collection<int, MarketingView>
     */
    public function getViewedAt(): Collection
    {
        return $this->viewedAt;
    }

    public function addViewedAt(MarketingView $viewedAt): self
    {
        if (!$this->viewedAt->contains($viewedAt)) {
            $this->viewedAt[] = $viewedAt;
            $viewedAt->setMarketing($this);
        }

        return $this;
    }

    public function removeViewedAt(MarketingView $viewedAt): self
    {
        if ($this->viewedAt->removeElement($viewedAt)) {
            // set the owning side to null (unless already changed)
            if ($viewedAt->getMarketing() === $this) {
                $viewedAt->setMarketing(null);
            }
        }

        return $this;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function setDuration(int $duration): self
    {
        $this->duration = $duration;

        return $this;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function setRedirectUrl(?string $redirectUrl): self
    {
        $this->redirectUrl = $redirectUrl;

        return $this;
    }

    public function getSponsorName(): ?string
    {
        return $this->sponsorName;
    }

    public function setSponsorName(?string $sponsorName): self
    {
        $this->sponsorName = $sponsorName;

        return $this;
    }

    public function getOffer(): ?string
    {
        return $this->offer;
    }

    public function setOffer(?string $offer): self
    {
        $this->offer = $offer;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function getBannerDesktop(): ?string
    {
        return $this->bannerDesktop;
    }

    public function setBannerDesktop(?string $bannerDesktop): self
    {
        $this->bannerDesktop = $bannerDesktop;

        return $this;
    }

    public function getBannerMobile(): ?string
    {
        return $this->bannerMobile;
    }

    public function setBannerMobile(?string $bannerMobile): self
    {
        $this->bannerMobile = $bannerMobile;

        return $this;
    }

    public function hasCta(): bool
    {
        return $this->showCta;
    }

    public function setShowCta(bool $showCta): self
    {
        $this->showCta = $showCta;

        return $this;
    }

    /** @return list<string> */
    public function getPlacements(): array
    {
        return $this->placements;
    }

    /** @param list<string> $placements */
    public function setPlacements(array $placements): self
    {
        $this->placements = array_values(array_intersect(self::PLACEMENTS, $placements));

        return $this;
    }

    public function hasPlacement(string $placement): bool
    {
        return in_array($placement, $this->placements, true);
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function isExclusive(): bool
    {
        return $this->exclusive;
    }

    public function setExclusive(bool $exclusive): self
    {
        $this->exclusive = $exclusive;

        return $this;
    }

    /** Diffusée ce jour-là (AAAA-MM-JJ) : statut actif, dates de début et de fin incluses. */
    public function isLiveOn(string $day): bool
    {
        return $this->status === 'active'
            && $this->start_date !== null && $this->start_date->format('Y-m-d') <= $day
            && ($this->end_date === null || $this->end_date->format('Y-m-d') >= $day);
    }
}
