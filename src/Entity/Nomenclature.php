<?php

namespace App\Entity;

use App\Repository\NomenclatureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Référentiel INAMI. Un code obsolète est DÉSACTIVÉ, jamais supprimé ni renuméroté (ADR-210) :
 * il n'est plus proposé pour une nouvelle intervention ni un nouveau favori, mais les interventions
 * historiques, les favoris, les statistiques et les exports Excel continuent d'y accéder (aucun
 * filtre Doctrine global sur `active`).
 */
#[ORM\Entity(repositoryClass: NomenclatureRepository::class)]
#[ORM\Index(name: 'idx_nomenclature_speciality_active', columns: ['speciality', 'active'])]
class Nomenclature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $speciality;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $codeAmbulant;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $codeHospitalisation;

    #[ORM\Column(type: 'string', length: 1000)]
    private $name;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private $type;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $subType;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $n;

    /** Code proposé pour une nouvelle intervention ou un nouveau favori. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    /** Date OFFICIELLE d'abrogation publiée par l'INAMI (facultative, saisie par l'administrateur). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inamiRepealedAt = null;

    /** Date et heure TECHNIQUES de la désactivation dans MedClick (fixées par le serveur). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    #[ORM\OneToMany(targetEntity: Surgeries::class, mappedBy: 'nomenclature')]
    private $surgeries;

    #[ORM\OneToMany(targetEntity: Favorites::class, mappedBy: 'surgery')]
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

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Désactive le code. La date technique n'est fixée qu'au passage d'actif à inactif : une
     * nouvelle demande sur un code déjà inactif conserve la date de la première désactivation.
     */
    public function deactivate(\DateTimeImmutable $at): self
    {
        if ($this->active) {
            $this->active = false;
            $this->deactivatedAt = $at;
        }

        return $this;
    }

    /** Réactive le code (la date technique de désactivation est effacée). */
    public function activate(): self
    {
        $this->active = true;
        $this->deactivatedAt = null;

        return $this;
    }

    public function getInamiRepealedAt(): ?\DateTimeImmutable
    {
        return $this->inamiRepealedAt;
    }

    public function setInamiRepealedAt(?\DateTimeImmutable $inamiRepealedAt): self
    {
        $this->inamiRepealedAt = $inamiRepealedAt;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }
}
