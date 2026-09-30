<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\SurgeonsRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;



use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"surgeons_read"}
 *      },
 *      collectionOperations={
 *          "get",
 *          "post"={"security_post_denormalize"="is_granted('OWNER', object)", "security_post_denormalize_message"="Cette ressource ne vous appartient pas."}
 *      },
 *      itemOperations={
 *          "get",
 *          "put"={"security_post_denormalize"="is_granted('OWNER', object)", "security_post_denormalize_message"="Cette ressource ne vous appartient pas."},
 *          "patch"={"security_post_denormalize"="is_granted('OWNER', object)", "security_post_denormalize_message"="Cette ressource ne vous appartient pas."},
 *          "delete"
 *      },
 *      attributes={
 *          "order": {"lastName": "ASC"}
 *      }
 * )
 */
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
