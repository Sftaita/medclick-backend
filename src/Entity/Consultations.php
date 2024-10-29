<?php

namespace App\Entity;

use App\Repository\ConsultationsRepository;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Core\Annotation\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=ConsultationsRepository::class)
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"consultation_read"}
 *      },
 *      attributes={
 *          "order": {"date": "DESC"}   
 *      }
 * )
 */
class Consultations
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * 
     * @Groups({"consultation_read"})
     */
    private $id;

    /**
     * @ORM\Column(type="date")
     * 
     * @Groups({"consultation_read"})
     * 
     * @Assert\NotBlank(message="Indiquer la date")
     */
    private $date;

    /**
     * @ORM\Column(type="string", length=10)
     * 
     * @Groups({"consultation_read"})
     * 
     * @Assert\NotBlank(message="Indiquer le nombre de consultation")
     * @Assert\Length(
     *      max = 3,
     *      maxMessage = "Ce nombre de consultation est improbable",
     * )
     *  @Assert\Type(
     *     type="numeric",
     *     message="Veuillez entrer un nombre."
     * )
     */
    private $number;

    /**
     * @ORM\ManyToOne(targetEntity=Years::class, inversedBy="consultations")
     * 
     * @Groups({"consultation_read"})
     */
    private $year;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     * 
     */
    private $moment;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     * 
     * @Groups({"consultation_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer le moment de la journée.")
     * @Assert\Length(
     *      max = 20,
     *      maxMessage = "Trop long",
     * )
     */
    private $dayPart;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * 
     * @Groups({"consultation_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer la spécialité pour laquelle vous avez consulté.")
     * @Assert\Length(
     *      max = 30,
     *      maxMessage = "Trop long",
     * )
     */
    private $speciality;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(string $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function getYear(): ?Years
    {
        return $this->year;
    }

    public function setYear(?Years $year): self
    {
        $this->year = $year;

        return $this;
    }

    public function getMoment(): ?string
    {
        return $this->moment;
    }

    public function setMoment(?string $moment): self
    {
        $this->moment = $moment;

        return $this;
    }

    public function getDayPart(): ?string
    {
        return $this->dayPart;
    }

    public function setDayPart(?string $dayPart): self
    {
        $this->dayPart = $dayPart;

        return $this;
    }

    public function getSpeciality(): ?string
    {
        return $this->speciality;
    }

    public function setSpeciality(?string $speciality): self
    {
        $this->speciality = $speciality;

        return $this;
    }
}
