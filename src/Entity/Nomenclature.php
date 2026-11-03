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

    /**
     * Justification d'une désactivation ADMINISTRATIVE (ex. doublon d'une autre ligne), distincte d'une
     * abrogation officielle INAMI (ADR-212). Effacée à la réactivation.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $deactivationReason = null;

    /** Référence de la publication officielle de l'abrogation (ex. « Moniteur belge du … »). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $inamiRepealSource = null;

    /**
     * Vérification de la date officielle d'abrogation par un administrateur (date et heure).
     * Effacée dès que la date ou la source change : une nouvelle vérification est alors exigée.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inamiRepealVerifiedAt = null;

    #[ORM\OneToMany(targetEntity: Surgeries::class, mappedBy: 'nomenclature')]
    private $surgeries;

    #[ORM\OneToMany(targetEntity: Favorites::class, mappedBy: 'surgery')]
    private $favorites;

    /**
     * Spécialités SUPPLÉMENTAIRES autorisées pour ce code (ADR-211), en plus de `speciality`.
     *
     * @var Collection<int, NomenclatureSharedSpeciality>
     */
    #[ORM\OneToMany(targetEntity: NomenclatureSharedSpeciality::class, mappedBy: 'nomenclature', cascade: ['persist'], orphanRemoval: true)]
    private Collection $sharedSpecialities;

    public function __construct()
    {
        $this->surgeries = new ArrayCollection();
        $this->favorites = new ArrayCollection();
        $this->sharedSpecialities = new ArrayCollection();
    }

    /**
     * Spécialités supplémentaires (hors spécialité principale), triées.
     *
     * @return list<string>
     */
    public function getSharedSpecialities(): array
    {
        $specialities = array_map(static fn (NomenclatureSharedSpeciality $s) => $s->getSpeciality(), $this->sharedSpecialities->toArray());
        sort($specialities);

        return array_values(array_unique($specialities));
    }

    /** Vrai si une intervention ou un favori peut être enregistré sous cette spécialité avec ce code. */
    public function allowsSpeciality(string $speciality): bool
    {
        return $speciality === $this->speciality || in_array($speciality, $this->getSharedSpecialities(), true);
    }

    /** Autorise une spécialité supplémentaire (sans effet si elle l'est déjà ou si c'est la principale). */
    public function shareWith(string $speciality): self
    {
        if (!$this->allowsSpeciality($speciality)) {
            $this->sharedSpecialities->add(new NomenclatureSharedSpeciality($this, $speciality));
        }

        return $this;
    }

    /** Retire une spécialité supplémentaire (les interventions et favoris déjà enregistrés ne changent pas). */
    public function unshareWith(string $speciality): self
    {
        foreach ($this->sharedSpecialities as $shared) {
            if ($shared->getSpeciality() === $speciality) {
                $this->sharedSpecialities->removeElement($shared);
            }
        }

        return $this;
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
        $this->deactivationReason = null;

        return $this;
    }

    /** Désactivation administrative justifiée : aucune donnée d'abrogation officielle n'est écrite (ADR-212). */
    public function deactivateAdministratively(\DateTimeImmutable $at, string $reason): self
    {
        $this->deactivate($at);
        $this->deactivationReason = $reason;

        return $this;
    }

    public function getDeactivationReason(): ?string
    {
        return $this->deactivationReason;
    }

    public function getInamiRepealedAt(): ?\DateTimeImmutable
    {
        return $this->inamiRepealedAt;
    }

    /** Une date différente efface la vérification (nouvelle vérification exigée). */
    public function setInamiRepealedAt(?\DateTimeImmutable $inamiRepealedAt): self
    {
        if ($this->inamiRepealedAt?->format('Y-m-d') !== $inamiRepealedAt?->format('Y-m-d')) {
            $this->inamiRepealVerifiedAt = null;
        }
        $this->inamiRepealedAt = $inamiRepealedAt;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function getInamiRepealSource(): ?string
    {
        return $this->inamiRepealSource;
    }

    /** Une source différente efface la vérification (nouvelle vérification exigée). */
    public function setInamiRepealSource(?string $source): self
    {
        $source = $source !== null && trim($source) !== '' ? trim($source) : null;
        if ($source !== $this->inamiRepealSource) {
            $this->inamiRepealVerifiedAt = null;
        }
        $this->inamiRepealSource = $source;

        return $this;
    }

    public function getInamiRepealVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->inamiRepealVerifiedAt;
    }

    /**
     * Atteste que la date officielle d'abrogation a été vérifiée dans la publication citée.
     * Une vérification existante (même date, même source) garde sa date d'origine.
     *
     * @throws \DomainException si la date officielle ou la source manque
     */
    public function verifyRepeal(\DateTimeImmutable $at): self
    {
        if ($this->inamiRepealedAt === null || $this->inamiRepealSource === null) {
            throw new \DomainException("La date officielle d'abrogation et sa source sont requises pour la vérification.");
        }
        $this->inamiRepealVerifiedAt ??= $at;

        return $this;
    }

    public function unverifyRepeal(): self
    {
        $this->inamiRepealVerifiedAt = null;

        return $this;
    }

    /** Date officielle d'abrogation, seulement si elle a été vérifiée (sinon null : aucune période supposée). */
    public function getVerifiedRepealDate(): ?\DateTimeImmutable
    {
        return $this->inamiRepealVerifiedAt !== null ? $this->inamiRepealedAt : null;
    }

    /**
     * Le code peut-il servir pour une intervention réalisée à cette date ?
     *
     * La date officielle d'abrogation VÉRIFIÉE prime toujours sur le statut administratif `active`
     * (décision du 02/11/2026) :
     * - abrogation vérifiée : utilisable seulement pour une intervention strictement antérieure,
     *   que le code soit actif ou non (une réactivation ne la contourne jamais) ;
     * - sans abrogation vérifiée : utilisable seulement si le code est actif (code inactif : aucune
     *   période de validité supposée).
     */
    public function isUsableOn(\DateTimeInterface $date): bool
    {
        $repealed = $this->getVerifiedRepealDate();
        if ($repealed !== null) {
            return $date->format('Y-m-d') < $repealed->format('Y-m-d');
        }

        return $this->active;
    }

    /**
     * Abrogation VÉRIFIÉE atteinte à cette date (date >= abrogation), que le code soit actif ou non.
     * Sert à refuser le déplacement d'une intervention existante dans la période abrogée.
     */
    public function isKnownRepealedOn(\DateTimeInterface $date): bool
    {
        $repealed = $this->getVerifiedRepealDate();

        return $repealed !== null && $date->format('Y-m-d') >= $repealed->format('Y-m-d');
    }
}
