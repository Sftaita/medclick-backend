<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\State\CurrentUserAssignProcessor;
use App\Repository\FavoritesRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Doctrine\Common\Collections\Collection;

use Doctrine\Common\Collections\ArrayCollection;

#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(processor: CurrentUserAssignProcessor::class),
        new Put(securityPostDenormalize: "is_granted('OWNER', object)", securityPostDenormalizeMessage: 'Cette ressource ne vous appartient pas.'),
        new Patch(securityPostDenormalize: "is_granted('OWNER', object)", securityPostDenormalizeMessage: 'Cette ressource ne vous appartient pas.'),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['favorites_read']],
    paginationEnabled: false,
    order: ['speciality' => 'ASC', 'shortcut' => 'ASC'],
)]
#[ORM\Entity(repositoryClass: FavoritesRepository::class)]
class Favorites
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['favorites_read'])]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['favorites_read'])]
    private $shortcut;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['favorites_read'])]
    private $SurgeryName;

    #[ORM\Column(type: 'string', length: 100)]
    #[Groups(['favorites_read'])]
    private $codeHospitalisation;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Groups(['favorites_read'])]
    private $speciality;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'favorites')]
    #[ORM\JoinColumn(nullable: false)]
    private $user;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class, inversedBy: 'favorites')]
    private $surgery;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShortcut(): ?string
    {
        return $this->shortcut;
    }

    public function setShortcut(string $shortcut): self
    {
        $this->shortcut = $shortcut;

        return $this;
    }

    public function getSurgeryName(): ?string
    {
        return $this->SurgeryName;
    }

    public function setSurgeryName(string $SurgeryName): self
    {
        $this->SurgeryName = $SurgeryName;

        return $this;
    }

    public function getCodeHospitalisation(): ?string
    {
        return $this->codeHospitalisation;
    }

    public function setCodeHospitalisation(string $codeHospitalisation): self
    {
        $this->codeHospitalisation = $codeHospitalisation;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getSurgery(): ?Nomenclature
    {
        return $this->surgery;
    }

    public function setSurgery(?Nomenclature $surgery): self
    {
        $this->surgery = $surgery;

        return $this;
    }
}
