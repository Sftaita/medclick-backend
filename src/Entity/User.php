<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\Collection;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * @ORM\Entity(repositoryClass=UserRepository::class)
 * @ApiResource(
 *      normalizationContext={
 *          "groups"={"user_read"}
 *      },
 *      attributes={
 *          "order": {"lastname": "ASC"}   
 *      }
 * )
 * @UniqueEntity("email",message="Cet utilisateur existe déjà")
 */
class User implements UserInterface
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     * 
     * @Groups({"user_read"})
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=180, unique=true)
     * @Groups({"user_read"})
     * 
     * @Assert\NotBlank(message="L'email doit être renseigné")
     * @Assert\Email(
     *     message = "L'email indiqué n'est pas valide"
     * )
     */
    private $email;

    /**
     * @ORM\Column(type="json")
     */
    private $roles = [];

    /**
     * @var string The hashed password
     * @ORM\Column(type="string")
     * 
     * @Assert\NotBlank(message="Un mot de passe est nécessaire")
     * @Assert\Length(
     *      min = 6,
     *      max = 50,
     *      minMessage = "Le mot de passe choisi est trop court",
     *      maxMessage = "Le mot de passe est trop long"
     * )
     */
    private $password;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"user_read"})
     * 
     * @Assert\NotBlank(message="Quel est votre prénom")
     * @Assert\Length(
     *      max = 50,
     *      maxMessage = "Le prénom est trop long"
     * )
     */
    private $firstname;

    /**
     * @ORM\Column(type="string", length=255)
     * @Groups({"user_read"})
     * 
     * @Assert\NotBlank(message="Quel est votre nom")
     * @Assert\Length(
     *      max = 50,
     *      maxMessage = "Le nom est trop long"
     * )
     */
    private $lastname;

    /**
     * @ORM\OneToMany(targetEntity=Years::class, mappedBy="user")
     */
    private $years;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    public $token;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * 
     * @Groups({"user_read"})
     */
    private $validatedAt;

    /**
     * @ORM\Column(type="string", length=10, nullable=true)
     * @Groups({"user_read"})
     */
    private $counter;

    /**
     * @ORM\OneToMany(targetEntity=Favorites::class, mappedBy="user")
     */
    private $favorites;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @Groups({"user_read"})
     * 
     * @Assert\Length(
     *      max = 100,
     *      maxMessage = "Le nom de specialité est trop long"
     * )
     */
    private $speciality;

    /**
     * @ORM\OneToOne(targetEntity=Statistics::class, mappedBy="user", cascade={"persist", "remove"})
     */
    private $firstHandSurgery;

    /**
     * @ORM\OneToOne(targetEntity=Statistics::class, mappedBy="user")
     */
    private $statistics;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private $resetToken;

    /**
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $acceptedTerms;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $termsAcceptedDate;

    public function __construct()
    {
        $this->years = new ArrayCollection();
        $this->favorites = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUsername(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getPassword(): string
    {
        return (string) $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getSalt()
    {
        // not needed when using the "bcrypt" algorithm in security.yaml
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials()
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): self
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): self
    {
        $this->lastname = $lastname;

        return $this;
    }

    /**
     * @return Collection|Years[]
     */
    public function getYears(): Collection
    {
        return $this->years;
    }

    public function addYear(Years $year): self
    {
        if (!$this->years->contains($year)) {
            $this->years[] = $year;
            $year->setUser($this);
        }

        return $this;
    }

    public function removeYear(Years $year): self
    {
        if ($this->years->contains($year)) {
            $this->years->removeElement($year);
            // set the owning side to null (unless already changed)
            if ($year->getUser() === $this) {
                $year->setUser(null);
            }
        }

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getValidatedAt(): ?\DateTimeInterface
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeInterface $validatedAt): self
    {
        $this->validatedAt = $validatedAt;

        return $this;
    }

    public function getCounter(): ?string
    {
        return $this->counter;
    }

    public function setCounter(?string $counter): self
    {
        $this->counter = $counter;

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
            $favorite->setUser($this);
        }

        return $this;
    }

    public function removeFavorite(Favorites $favorite): self
    {
        if ($this->favorites->removeElement($favorite)) {
            // set the owning side to null (unless already changed)
            if ($favorite->getUser() === $this) {
                $favorite->setUser(null);
            }
        }

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

    public function getFirstHandSurgery(): ?Statistics
    {
        return $this->firstHandSurgery;
    }

    public function setFirstHandSurgery(Statistics $firstHandSurgery): self
    {
        $this->firstHandSurgery = $firstHandSurgery;

        // set the owning side of the relation if necessary
        if ($firstHandSurgery->getUser() !== $this) {
            $firstHandSurgery->setUser($this);
        }

        return $this;
    }

    public function getStatistics(): ?Statistics
    {
        return $this->statistics;
    }

    public function setStatistics(?Statistics $statistics): self
    {
        $this->statistics = $statistics;

        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): self
    {
        $this->resetToken = $resetToken;

        return $this;
    }

    public function getAcceptedTerms(): ?bool
    {
        return $this->acceptedTerms;
    }

    public function setAcceptedTerms(?bool $acceptedTerms): self
    {
        $this->acceptedTerms = $acceptedTerms;

        // Définir la date d'acceptation si elle n'est pas déjà définie
        if ($acceptedTerms && $this->termsAcceptedDate === null) {
            $this->termsAcceptedDate = new \DateTimeImmutable();
        }

        return $this;
    }


    public function getTermsAcceptedDate(): ?\DateTimeInterface
    {
        return $this->termsAcceptedDate;
    }

    public function setTermsAcceptedDate(?\DateTimeInterface $termsAcceptedDate): self
    {
        $this->termsAcceptedDate = $termsAcceptedDate;

        return $this;
    }
}
