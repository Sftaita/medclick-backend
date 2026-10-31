<?php

declare(strict_types=1);

namespace App\Service;

use App\Advertising\Emplacements;
use App\Entity\AdPlacementSetting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réglages des emplacements (activé, fréquence, ordre). Ligne absente : emplacement activé,
 * réglages par défaut (créée à la première modification).
 */
final class EmplacementSettings
{
    /** @var array<string, AdPlacementSetting>|null */
    private ?array $cache = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(string $key): AdPlacementSetting
    {
        if (!Emplacements::exists($key)) {
            throw new \InvalidArgumentException('Emplacement inconnu.');
        }
        $this->cache ??= $this->load();
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = new AdPlacementSetting($key);
        }

        return $this->cache[$key];
    }

    /** Réglage à enregistrer : la ligne est créée si besoin. */
    public function forUpdate(string $key): AdPlacementSetting
    {
        $setting = $this->get($key);
        if (!$this->em->contains($setting)) {
            $this->em->persist($setting);
        }

        return $setting;
    }

    public function isEnabled(string $key): bool
    {
        return $this->get($key)->isEnabled();
    }

    /** @return array<string, AdPlacementSetting> */
    private function load(): array
    {
        $settings = [];
        foreach ($this->em->getRepository(AdPlacementSetting::class)->findAll() as $setting) {
            $settings[$setting->getEmplacement()] = $setting;
        }

        return $settings;
    }

    /** Pour les tests (plusieurs requêtes dans le même noyau). */
    public function reset(): void
    {
        $this->cache = null;
    }
}
