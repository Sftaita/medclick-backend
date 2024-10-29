<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\StatisticsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;


/**
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"statistics_read"}
 *      },
 * 
 * ),
 * @ORM\Entity(repositoryClass=StatisticsRepository::class)
 */
class Statistics
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * 
     * @Groups({"statistics_read"})
     */
    private $id;

    /**
     * @ORM\OneToOne(targetEntity=User::class, inversedBy="firstHandSurgery")
     * @ORM\JoinColumn(nullable=false)
     */
    private $user;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Groups({"statistics_read"})
     */
    private $firstHandSurgeries;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Groups({"statistics_read"})
     */
    private $secondHandSurgeries;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $fistHandHelpedSurgeries;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Groups({"statistics_read"})
     */
    private $consultations;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Groups({"statistics_read"})
     */
    private $gardes;

    /**
     * @ORM\Column(type="integer", nullable=true)
     * @Groups({"statistics_read"})
     */
    private $formations;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getFirstHandSurgeries(): ?int
    {
        return $this->firstHandSurgeries;
    }

    public function setFirstHandSurgeries(?int $firstHandSurgeries): self
    {
        $this->firstHandSurgeries = $firstHandSurgeries;

        return $this;
    }

    public function getSecondHandSurgeries(): ?int
    {
        return $this->secondHandSurgeries;
    }

    public function setSecondHandSurgeries(?int $secondHandSurgeries): self
    {
        $this->secondHandSurgeries = $secondHandSurgeries;

        return $this;
    }

    public function getFistHandHelpedSurgeries(): ?int
    {
        return $this->fistHandHelpedSurgeries;
    }

    public function setFistHandHelpedSurgeries(?int $fistHandHelpedSurgeries): self
    {
        $this->fistHandHelpedSurgeries = $fistHandHelpedSurgeries;

        return $this;
    }

    public function getConsultations(): ?int
    {
        return $this->consultations;
    }

    public function setConsultations(?int $consultations): self
    {
        $this->consultations = $consultations;

        return $this;
    }

    public function getGardes(): ?int
    {
        return $this->gardes;
    }

    public function setGardes(?int $gardes): self
    {
        $this->gardes = $gardes;

        return $this;
    }

    public function getFormations(): ?int
    {
        return $this->formations;
    }

    public function setFormations(?int $formations): self
    {
        $this->formations = $formations;

        return $this;
    }
}
