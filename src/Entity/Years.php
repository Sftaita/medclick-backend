<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\YearsRepository;
use ApiPlatform\Core\Annotation\ApiFilter;
use Doctrine\Common\Collections\Collection;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;


/**
 * @ORM\Entity(repositoryClass=YearsRepository::class)
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"surgeries_read"}
 *      },
 *      attributes={
 *          "order": {"yearOfFormation": "DESC"}   
 *      }
 * )
 *
 */
class Years
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"surgeries_read", "consultation_read", "surgeons_read", "formations_read", "gardes_read"})
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity=User::class, inversedBy="years")
     * @ORM\JoinColumn(nullable=false)
     * @Groups({"surgeries_read"})
     */
    private $user;

    /**
     * @ORM\Column(type="string")
     * @Groups({"surgeries_read", "consultation_read", "formations_read", "gardes_read"})
     * 
     * @Assert\NotBlank(message="En quelle année êtes vous?")
     */
    private $yearOfFormation;

    /**
     * @ORM\Column(type="date")
     * @Groups({"surgeries_read"})
     * 
     * @Assert\NotBlank(message="A quelle date avez vous commencez ce stage?")
     * 
     */
    private $dateOfStart;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"surgeries_read"})
     * 
     * @Assert\NotBlank(message="Veuillez renseigner l'hopital")
     * @Assert\Length(
     *      max = 150,
     *      maxMessage = "Le nom de l'hopital est trop long",
     * )
     */
    private $hospital;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"surgeries_read"})
     * 
     * * @Assert\NotBlank(message="Qui est votre maitre de stage?")
     */
    private $master;

    /**
     * @ORM\OneToMany(targetEntity=Surgeries::class, mappedBy="year")
     */
    private $surgeries;

    /**
     * @Groups({"surgeries_read"})
     * 
     * @ORM\OneToMany(targetEntity=Surgeons::class, mappedBy="year")
     */
    private $Surgeons;

    /**
     * @ORM\OneToMany(targetEntity=Consultations::class, mappedBy="year")
     */
    private $consultations;

    /**
     * @ORM\OneToMany(targetEntity=Formations::class, mappedBy="year")
     */
    private $formations;

    /**
     * @ORM\OneToMany(targetEntity=Gardes::class, mappedBy="year")
     */
    private $gardes;

    public function __construct()
    {
        $this->surgeries = new ArrayCollection();
        $this->Surgeons = new ArrayCollection();
        $this->consultations = new ArrayCollection();
        $this->formations = new ArrayCollection();
        $this->gardes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getYearOfFormation(): ?string
    {
        return $this->yearOfFormation;
    }

    public function setYearOfFormation(string $yearOfFormation): self
    {
        $this->yearOfFormation = $yearOfFormation;

        return $this;
    }

    public function getDateOfStart(): ?\DateTimeInterface
    {
        return $this->dateOfStart;
    }

    public function setDateOfStart(\DateTimeInterface $dateOfStart): self
    {
        $this->dateOfStart = $dateOfStart;

        return $this;
    }

    public function getHospital(): ?string
    {
        return $this->hospital;
    }

    public function setHospital(string $hospital): self
    {
        $this->hospital = $hospital;

        return $this;
    }

    public function getMaster(): ?string
    {
        return $this->master;
    }

    public function setMaster(string $master): self
    {
        $this->master = $master;

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
            $surgery->setYear($this);
        }

        return $this;
    }

    public function removeSurgery(Surgeries $surgery): self
    {
        if ($this->surgeries->contains($surgery)) {
            $this->surgeries->removeElement($surgery);
            // set the owning side to null (unless already changed)
            if ($surgery->getYear() === $this) {
                $surgery->setYear(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Surgeons[]
     */
    public function getSurgeons(): Collection
    {
        return $this->Surgeons;
    }

    public function addSurgeon(Surgeons $surgeon): self
    {
        if (!$this->Surgeons->contains($surgeon)) {
            $this->Surgeons[] = $surgeon;
            $surgeon->setYear($this);
        }

        return $this;
    }

    public function removeSurgeon(Surgeons $surgeon): self
    {
        if ($this->Surgeons->contains($surgeon)) {
            $this->Surgeons->removeElement($surgeon);
            // set the owning side to null (unless already changed)
            if ($surgeon->getYear() === $this) {
                $surgeon->setYear(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Consultations[]
     */
    public function getConsultations(): Collection
    {
        return $this->consultations;
    }

    public function addConsultation(Consultations $consultation): self
    {
        if (!$this->consultations->contains($consultation)) {
            $this->consultations[] = $consultation;
            $consultation->setYear($this);
        }

        return $this;
    }

    public function removeConsultation(Consultations $consultation): self
    {
        if ($this->consultations->contains($consultation)) {
            $this->consultations->removeElement($consultation);
            // set the owning side to null (unless already changed)
            if ($consultation->getYear() === $this) {
                $consultation->setYear(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Formations[]
     */
    public function getFormations(): Collection
    {
        return $this->formations;
    }

    public function addFormation(Formations $formation): self
    {
        if (!$this->formations->contains($formation)) {
            $this->formations[] = $formation;
            $formation->setYear($this);
        }

        return $this;
    }

    public function removeFormation(Formations $formation): self
    {
        if ($this->formations->removeElement($formation)) {
            // set the owning side to null (unless already changed)
            if ($formation->getYear() === $this) {
                $formation->setYear(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Gardes[]
     */
    public function getGardes(): Collection
    {
        return $this->gardes;
    }

    public function addGarde(Gardes $garde): self
    {
        if (!$this->gardes->contains($garde)) {
            $this->gardes[] = $garde;
            $garde->setYear($this);
        }

        return $this;
    }

    public function removeGarde(Gardes $garde): self
    {
        if ($this->gardes->removeElement($garde)) {
            // set the owning side to null (unless already changed)
            if ($garde->getYear() === $this) {
                $garde->setYear(null);
            }
        }

        return $this;
    }
}
