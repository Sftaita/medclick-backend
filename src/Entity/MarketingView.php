<?php

namespace App\Entity;

use App\Repository\MarketingViewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=MarketingViewRepository::class)
 */
class MarketingView
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=marketing::class, inversedBy="viewedAt")
     * @ORM\JoinColumn(nullable=false)
     */
    private $marketing;

    /**
     * @ORM\Column(type="datetime")
     */
    private $viewedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMarketing(): ?marketing
    {
        return $this->marketing;
    }

    public function setMarketing(?marketing $marketing): self
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
}
