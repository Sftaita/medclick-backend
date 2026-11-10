<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailUnsubscribeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Liste d'opposition aux communications e-mail (désinscriptions). Une adresse présente ici ne
 * reçoit plus aucune campagne, quel que soit son compte : la ligne survit à la suppression ou à
 * la recréation du compte. Les e-mails de service (activation, mot de passe) ne sont pas concernés.
 */
#[ORM\Entity(repositoryClass: EmailUnsubscribeRepository::class)]
#[ORM\Table(name: 'email_unsubscribe')]
#[ORM\UniqueConstraint(name: 'uniq_email_unsubscribe_email', columns: ['email'])]
class EmailUnsubscribe
{
    /** Lien de désinscription de l'e-mail (page de confirmation). */
    public const SOURCE_LINK = 'link';
    /** Désinscription en un clic proposée par la messagerie (en-tête List-Unsubscribe-Post, RFC 8058). */
    public const SOURCE_ONE_CLICK = 'one_click';
    /** Demande reçue autrement (contact@medclick.be…), saisie dans l'administration. */
    public const SOURCE_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Adresse en minuscules, sans espaces. */
    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $source;

    /** Campagne dont le lien a été utilisé (null pour une saisie admin). */
    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    private ?string $campaign;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $source, ?string $campaign = null)
    {
        $this->email = self::normalize($email);
        $this->source = $source;
        $this->campaign = $campaign;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCampaign(): ?string
    {
        return $this->campaign;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
