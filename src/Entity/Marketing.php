<?php

namespace App\Entity;

use App\Repository\MarketingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=MarketingRepository::class)
 */
class Marketing
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=400)
     */
    private $campaign_name;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $status;

    /**
     * @ORM\Column(type="date")
     */
    private $start_date;

    /**
     * @ORM\Column(type="date", nullable=true)
     */
    private $end_date;

    /**
     * @ORM\Column(type="integer", options={"default" : 0})
     */
    private $views = 0;

    /**
     * @ORM\Column(type="integer", options={"default" : 0})
     */
    private $clicks = 0;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private $smartphone_format;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private $tablet_portrait_format;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private $tablet_landscape_format;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private $screen_14_inch_format;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private $large_screen_format;

    /**
     * @ORM\OneToMany(targetEntity=MarketingView::class, mappedBy="marketing")
     */
    private $viewedAt;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"default": 5})
     */
    private $duration;

    /**
     * @ORM\Column(type="string", length=500, nullable=true)
     */
    private $redirectUrl;

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

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): self
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
    
}
