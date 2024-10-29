<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\SurgeriesRepository;
use ApiPlatform\Core\Annotation\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=SurgeriesRepository::class)
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"surgeries_read"}
 *      },
 *      attributes={
 *          "order": {"date": "DESC", "id" : "DESC"}   
 *      }
 * )
 */
class Surgeries
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * @Groups({"surgeries_read"})
     */
    private $id;

    /**
     * @ORM\Column(type="date")
     * @Groups({"surgeries_read"})
     * 
     * @Assert\NotBlank(message="Indiquer la date")
     * 
     */
    private $date;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"surgeries_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer la spécialité.")
     * @Assert\Length(
     *      max = 150,
     *      maxMessage = "Le nom de la specialité est trop long",
     * )
     */
    private $speciality;

    /**
     * @ORM\Column(type="string", length=1000)
     * @Groups({"surgeries_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer le titre de l'intervention.")
     * @Assert\Length(
     *      max = 500,
     *      maxMessage = "Le nom de l'intervention est trop long",
     * )
     */
    private $name;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"surgeries_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer votre rôle durant l'intervention.")
     */
    private $position;

    /**
     * @ORM\ManyToOne(targetEntity=Years::class, inversedBy="surgeries")
     * @Groups({"surgeries_read"})
     * 
     * @Assert\NotBlank(message="L'intervention doit être liée à une année de formation")
     */
    private $year;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Groups({"surgeries_read"})
     * 
     */
    private $firstHand;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Groups({"surgeries_read"})
     */
    private $secondHand;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     * 
     */
    private $code;

    /**
     * @ORM\ManyToOne(targetEntity=Nomenclature::class, inversedBy="surgeries")
     */
    private $nomenclature;

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

    public function getSpeciality(): ?string
    {
        return $this->speciality;
    }

    public function setSpeciality(string $speciality): self
    {
        $this->speciality = $speciality;

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

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(string $position): self
    {
        $this->position = $position;

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

    public function getFirstHand(): ?string
    {
        return $this->firstHand;
    }

    public function setFirstHand(?string $firstHand): self
    {
        $this->firstHand = $firstHand;

        return $this;
    }

    public function getSecondHand(): ?string
    {
        return $this->secondHand;
    }

    public function setSecondHand(?string $secondHand): self
    {
        $this->secondHand = $secondHand;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getNomenclature(): ?Nomenclature
    {
        return $this->nomenclature;
    }

    public function setNomenclature(?Nomenclature $nomenclature): self
    {
        $this->nomenclature = $nomenclature;

        return $this;
    }
}
