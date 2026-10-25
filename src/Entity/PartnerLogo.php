<?php

namespace App\Entity;

use App\Repository\PartnerLogoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Logo d'un partenaire (PNG ou SVG validé), stocké en base : inclus dans les sauvegardes
 * chiffrées et les restaurations, indépendant des conteneurs (redéploiements). Adressé par son
 * empreinte SHA-256 : un même fichier n'est stocké qu'une fois, et son URL publique
 * (/api/partner/logo/<sha256>.<ext>) ne change jamais, donc se met en cache sans risque.
 */
#[ORM\Entity(repositoryClass: PartnerLogoRepository::class)]
#[ORM\Table(name: 'partner_logo')]
#[ORM\UniqueConstraint(name: 'uniq_partner_logo_sha256', columns: ['sha256'])]
class PartnerLogo
{
    public const MIME_PNG = 'image/png';
    public const MIME_SVG = 'image/svg+xml';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $sha256;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $mimeType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    /** Dimensions en pixels (PNG) ; null pour un SVG (vectoriel). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    /** @var resource|string */
    #[ORM\Column(type: Types::BLOB)]
    private $data;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $data, string $mimeType, ?int $width = null, ?int $height = null)
    {
        $this->data = $data;
        $this->sha256 = hash('sha256', $data);
        $this->mimeType = $mimeType;
        $this->size = strlen($data);
        $this->width = $width;
        $this->height = $height;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    /** Contenu binaire (Doctrine renvoie un flux pour une colonne BLOB). */
    public function getData(): string
    {
        if (is_resource($this->data)) {
            rewind($this->data);

            return (string) stream_get_contents($this->data);
        }

        return (string) $this->data;
    }

    /** Nom de fichier public : <sha256>.png ou <sha256>.svg. */
    public function getFileName(): string
    {
        return $this->sha256 . ($this->mimeType === self::MIME_SVG ? '.svg' : '.png');
    }
}
