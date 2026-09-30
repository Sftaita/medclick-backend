<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\State\SurgeonProcessor;
use App\State\SurgeonRemoveProcessor;
use App\Repository\SurgeonsRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(securityPostDenormalize: "is_granted('OWNER', object)", securityPostDenormalizeMessage: 'Cette ressource ne vous appartient pas.', processor: SurgeonProcessor::class),
        new Put(securityPostDenormalize: "is_granted('OWNER', object)", securityPostDenormalizeMessage: 'Cette ressource ne vous appartient pas.', processor: SurgeonProcessor::class),
        new Patch(securityPostDenormalize: "is_granted('OWNER', object)", securityPostDenormalizeMessage: 'Cette ressource ne vous appartient pas.', processor: SurgeonProcessor::class),
        new Delete(processor: SurgeonRemoveProcessor::class),
    ],
    normalizationContext: ['groups' => ['surgeons_read']],
    order: ['lastName' => 'ASC'],
)]
#[ORM\Entity(repositoryClass: SurgeonsRepository::class)]
class Surgeons
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['surgeons_read'])]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['surgeons_read'])]
    private $firstName;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['surgeons_read'])]
    private $lastName;

    #[ORM\ManyToOne(targetEntity: Years::class, inversedBy: 'Surgeons')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['surgeons_read'])]
    private $year;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['surgeons_read'])]
    private $boss;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

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

    public function getBoss(): ?bool
    {
        return $this->boss;
    }

    public function setBoss(?bool $boss): self
    {
        $this->boss = $boss;

        return $this;
    }
}
