<?php

namespace App\Entity;

use App\Repository\MarketingImageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Visuel d'une campagne importé depuis l'administration, toujours réencodé en WebP
 * (MarketingImageProcessor). Stocké en base comme les logos partenaires : inclus dans les
 * sauvegardes chiffrées et les restaurations, indépendant des conteneurs (redéploiements).
 *
 * Adressé par l'empreinte SHA-256 du WebP produit : un même visuel n'est stocké qu'une fois et
 * son URL (/api/marketing/image/<sha256>.webp) ne change jamais. Les colonnes *_format de
 * Marketing contiennent cette URL relative, ou une URL externe (anciennes campagnes).
 */
#[ORM\Entity(repositoryClass: MarketingImageRepository::class)]
#[ORM\Table(name: 'marketing_image')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_image_sha256', columns: ['sha256'])]
class MarketingImage
{
    public const MIME_TYPE = 'image/webp';
    public const PATH_PREFIX = '/api/marketing/image/';
    public const PATH_PATTERN = '#^/api/marketing/image/([a-f0-9]{64})\.webp$#';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $sha256;

    #[ORM\Column(type: Types::INTEGER)]
    private int $width;

    #[ORM\Column(type: Types::INTEGER)]
    private int $height;

    /** Taille du WebP stocké, en octets. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    /** Taille du fichier envoyé, en octets (gain de l'optimisation). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $originalSize;

    /** @var resource|string */
    #[ORM\Column(type: Types::BLOB)]
    private $data;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $data, int $width, int $height, int $originalSize)
    {
        $this->data = $data;
        $this->sha256 = hash('sha256', $data);
        $this->size = strlen($data);
        $this->width = $width;
        $this->height = $height;
        $this->originalSize = $originalSize;
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

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getOriginalSize(): int
    {
        return $this->originalSize;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getFileName(): string
    {
        return $this->sha256 . '.webp';
    }

    /** URL relative enregistrée dans la campagne : servie par le backend sur le domaine MedClick. */
    public function getPath(): string
    {
        return self::PATH_PREFIX . $this->getFileName();
    }
}
