<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\FormationsRepository;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity(repositoryClass=FormationsRepository::class)
 * 
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"formations_read"}
 *      },
 *      attributes={
 *          "order": {"dateOfStart": "DESC"}   
 *      }
 * )
 */
class Formations
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * 
     * @Groups({"formations_read"})
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * 
     * @Groups({"formations_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer le type d'évènement.")
     * @Assert\Length(
     *      max = 255,
     *      maxMessage = "Le nom est trop long",
     * )
     */
    private $event;

    /**
     * @ORM\Column(type="datetime")
     * 
     * @Groups({"formations_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer la date de début.")
     */
    private $dateOfStart;

    /**
     * @ORM\Column(type="datetime")
     * 
     * @Groups({"formations_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer la date de fin.")
     */
    private $dateOfEnd;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * 
     * @Groups({"formations_read"})
     * 
     * @Assert\NotBlank(message="Veuillez indiquer le titre de l'évènement.")
     */
    private $name;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * 
     * @Groups({"formations_read"})
     * 
     * @Assert\NotBlank(message="Décrire brièvement l'évènement.")
     * @Assert\Length(
     *      max = 255,
     *      maxMessage = "La description est trop longue (max 255 charactères)",
     * )
     */
    private $description;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * 
     * @Groups({"formations_read"})
     * 
     * @Assert\NotBlank(message="Ou l'évènement à t-il eu lieu?")
     * @Assert\Length(
     *      max = 255,
     *      maxMessage = "L'adresse est trop longue",
     * )
     */
    private $location;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * 
     * @Groups({"formations_read"})
     * 
     * @Assert\NotBlank(message="A quel titre y assistiez vous?")
     * @Assert\Length(
     *      max = 255,
     *      maxMessage = "Votre rôle est trop long",
     * )
     */
    private $role;

    /**
     * @ORM\ManyToOne(targetEntity=Years::class, inversedBy="formations")
     * @ORM\JoinColumn(nullable=false)
     * 
     * @Groups({"formations_read"})
     */
    private $year;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): ?string
    {
        return $this->event;
    }

    public function setEvent(string $event): self
    {
        $this->event = $event;

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

    public function getDateOfEnd(): ?\DateTimeInterface
    {
        return $this->dateOfEnd;
    }

    public function setDateOfEnd(\DateTimeInterface $dateOfEnd): self
    {
        $this->dateOfEnd = $dateOfEnd;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;

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
