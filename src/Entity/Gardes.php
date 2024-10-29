<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\GardesRepository;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=GardesRepository::class)
 * 
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"gardes_read"}
 *      },
 *      attributes={
 *          "order": {"dateOfStart": "DESC"}   
 *      }
 * )
 */
class Gardes
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * 
     * @Groups({"gardes_read"})
     */
    private $id;

    /**
     * @ORM\Column(type="datetime")
     * 
     * @Groups({"gardes_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer la date de début.")
     */
    private $dateOfStart;

    /**
     * @ORM\Column(type="datetime")
     * 
     * @Groups({"gardes_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer la date de fin.")
     */
    private $dateOfEnd;

    /**
     * @ORM\Column(type="string", length=10)
     * 
     * @Groups({"gardes_read"})
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
     * @ORM\ManyToOne(targetEntity=Years::class, inversedBy="gardes")
     * @ORM\JoinColumn(nullable=false)
     * 
     * @Groups({"gardes_read"})
     */
    private $year;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDateOfEnd(): ?\DateTimeInterface
    {
        return $this->dateOfEnd;
    }

    public function setDateOfEnd(\DateTimeInterface $dateOfEnd): self
    {
        $this->dateOfEnd = $dateOfEnd;

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
}
