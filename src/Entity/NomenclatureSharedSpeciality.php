<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Spécialité SUPPLÉMENTAIRE autorisée pour un acte de la nomenclature (ADR-211).
 *
 * Un code INAMI n'a qu'une seule ligne dans `nomenclature` (sa spécialité principale). Lorsqu'un
 * sous-article officiel relève de plusieurs spécialités (ex. article 14 n), « chirurgie orthopédique
 * (DP) et neurochirurgie (DA) »), chaque spécialité supplémentaire est une ligne de cette table :
 * l'acte apparaît alors aussi dans la liste de cette spécialité, et une intervention ou un favori
 * peut y être enregistré sous cette spécialité, avec le même code et la même nomenclature.
 *
 * Alimentée uniquement par un plan vérifié (`app:nomenclature:share-speciality`), jamais déduite
 * d'un libellé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nomenclature_shared_speciality')]
#[ORM\UniqueConstraint(name: 'uniq_nomenclature_shared_speciality', columns: ['nomenclature_id', 'speciality'])]
#[ORM\Index(name: 'idx_nomenclature_shared_speciality_speciality', columns: ['speciality'])]
class NomenclatureSharedSpeciality
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Nomenclature::class, inversedBy: 'sharedSpecialities')]
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
