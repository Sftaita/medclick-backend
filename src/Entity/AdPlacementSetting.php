<?php

declare(strict_types=1);

namespace App\Entity;

use App\Advertising\Emplacements;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages d'un emplacement (App\Advertising\Emplacements) : activé ou non, fréquence du plein
 * écran, ordre des publicités. Une ligne par emplacement, créée par la migration ; une ligne
 * absente vaut « activé, réglages par défaut ».
 *
 * displayOrder : identifiants des publicités dans l'ordre d'affichage publié ; pendingOrder :
 * ordre modifié dans l'administration et pas encore publié (null : aucun changement).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ad_placement_setting')]
class AdPlacementSetting
{
    #[ORM\Id]
    #[ORM\Column(name: 'emplacement', type: Types::STRING, length: 40)]
    private string $emplacement;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $frequency = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $displayOrder = [];

    /** @var list<int>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pendingOrder = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $emplacement)
    {
        $this->emplacement = $emplacement;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getEmplacement(): string
    {
        return $this->emplacement;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this->touch();
    }

    public function getFrequency(): string
    {
        return $this->frequency ?? Emplacements::DEFAULT_FREQUENCY;
    }

    public function setFrequency(?string $frequency): self
    {
        $this->frequency = $frequency;

        return $this->touch();
    }

    /** @return list<int> */
    public function getDisplayOrder(): array
    {
        return array_values(array_map('intval', $this->displayOrder));
    }

    /** @param list<int> $order */
    public function setDisplayOrder(array $order): self
    {
        $this->displayOrder = array_values(array_unique(array_map('intval', $order)));

        return $this->touch();
    }

    /** @return list<int>|null */
    public function getPendingOrder(): ?array
    {
        return $this->pendingOrder === null ? null : array_values(array_map('intval', $this->pendingOrder));
    }

    /** @param list<int>|null $order */
    public function setPendingOrder(?array $order): self
    {
        $this->pendingOrder = $order === null ? null : array_values(array_unique(array_map('intval', $order)));

        return $this->touch();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
