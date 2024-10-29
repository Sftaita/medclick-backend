<?php

namespace App\Entity;

use App\Repository\NomenclatureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=NomenclatureRepository::class)
 */
class Nomenclature
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $speciality;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $codeAmbulant;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $codeHospitalisation;

    /**
     * @ORM\Column(type="string", length=1000)
     */
    private $name;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $type;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $subType;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $n;

    /**
     * @ORM\OneToMany(targetEntity=Surgeries::class, mappedBy="nomenclature")
     */
    private $surgeries;

    /**
     * @ORM\OneToMany(targetEntity=Favorites::class, mappedBy="surgery")
     */
    private $favorites;

    public function __construct()
    {
        $this->surgeries = new ArrayCollection();
        $this->favorites = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSpeciality(): ?string
    {
        return $this->speciality;
    }

    public function setSpeciality(string $speciality): self
    {
        $this->speciality = $speciality;

        return $this;
    }

    public function getCodeAmbulant(): ?string
    {
        return $this->codeAmbulant;
    }

    public function setCodeAmbulant(?string $codeAmbulant): self
    {
        $this->codeAmbulant = $codeAmbulant;

        return $this;
    }

    public function getCodeHospitalisation(): ?string
    {
        return $this->codeHospitalisation;
    }

    public function setCodeHospitalisation(?string $codeHospitalisation): self
    {
        $this->codeHospitalisation = $codeHospitalisation;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getSubType(): ?string
    {
        return $this->subType;
    }

    public function setSubType(?string $subType): self
    {
        $this->subType = $subType;

        return $this;
    }

    public function getN(): ?string
    {
        return $this->n;
    }

    public function setN(?string $n): self
    {
        $this->n = $n;

        return $this;
    }

    /**
     * @return Collection|Surgeries[]
     */
    public function getSurgeries(): Collection
    {
        return $this->surgeries;
    }

    public function addSurgery(Surgeries $surgery): self
    {
        if (!$this->surgeries->contains($surgery)) {
            $this->surgeries[] = $surgery;
            $surgery->setNomenclature($this);
        }

        return $this;
    }

    public function removeSurgery(Surgeries $surgery): self
    {
        if ($this->surgeries->removeElement($surgery)) {
            // set the owning side to null (unless already changed)
            if ($surgery->getNomenclature() === $this) {
                $surgery->setNomenclature(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Favorites[]
     */
    public function getFavorites(): Collection
    {
        return $this->favorites;
    }

    public function addFavorite(Favorites $favorite): self
    {
        if (!$this->favorites->contains($favorite)) {
            $this->favorites[] = $favorite;
            $favorite->setSurgery($this);
        }

        return $this;
    }

    public function removeFavorite(Favorites $favorite): self
    {
        if ($this->favorites->removeElement($favorite)) {
            // set the owning side to null (unless already changed)
            if ($favorite->getSurgery() === $this) {
                $favorite->setSurgery(null);
            }
        }

        return $this;
    }
}
