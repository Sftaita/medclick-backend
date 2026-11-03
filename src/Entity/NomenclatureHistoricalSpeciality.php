<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Classement historique d'un code dans le récapitulatif Excel (ADR-211), distinct de l'autorisation
 * de saisie (NomenclatureSharedSpeciality).
 *
 * Une intervention DÉJÀ enregistrée sous cette spécialité est listée avec les interventions de cette
 * spécialité, même si le code n'est pas (ou plus) proposé pour une nouvelle saisie. Cette table
 * n'ouvre aucun droit de sélection et ne modifie aucune intervention.
 *
 * Alimentée uniquement par un plan vérifié (`app:nomenclature:share-speciality`), d'après les références
 * officielles NomenSoft (sous-article 14 n), jamais déduite d'un libellé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nomenclature_historical_speciality')]
#[ORM\UniqueConstraint(name: 'uniq_nomenclature_historical_speciality', columns: ['nomenclature_id', 'speciality'])]
class NomenclatureHistoricalSpeciality
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class)]
    #[ORM\JoinColumn(name: 'nomenclature_id', nullable: false, onDelete: 'CASCADE')]
    private Nomenclature $nomenclature;

    #[ORM\Column(type: 'string', length: 50)]
    private string $speciality;

    public function __construct(Nomenclature $nomenclature, string $speciality)
    {
        $this->nomenclature = $nomenclature;
        $this->speciality = $speciality;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomenclature(): Nomenclature
    {
        return $this->nomenclature;
    }

    public function getSpeciality(): string
    {
        return $this->speciality;
    }
}
