<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur quotidien de la recherche publique des codes INAMI (InamiSearchStats) : visites de la
 * page, ou consultations d'une spécialité. Agrégé par jour : aucune donnée nominative, aucune
 * adresse IP, aucun terme de recherche.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inami_search_stat')]
#[ORM\UniqueConstraint(name: 'uniq_inami_search_stat', columns: ['day', 'kind', 'speciality'])]
class InamiSearchStat
{
    public const KIND_VISIT = 'visit';
    public const KIND_SPECIALITY = 'speciality';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(type: 'string', length: 20)]
    private string $kind;

    /** Spécialité consultée (« all » : toutes) ; chaîne vide pour une visite. */
    #[ORM\Column(type: 'string', length: 50, options: ['default' => ''])]
    private string $speciality = '';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $count = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getSpeciality(): string
    {
        return $this->speciality;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
