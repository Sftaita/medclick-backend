<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nomenclature INAMI, validité historique (ADR-210) : vérification de la date officielle d'abrogation.
 *
 * Additif uniquement : deux colonnes facultatives, aucune ligne modifiée.
 * - inami_repeal_source : référence de la publication officielle (ex. Moniteur belge du …) ;
 * - inami_repeal_verified_at : date et heure de la vérification par un administrateur.
 *
 * Un code inactif n'est utilisable pour une intervention antérieure à son abrogation que si cette
 * date est vérifiée : une date absente ou non vérifiée n'ouvre jamais de période de validité.
 * Retour arrière : down() supprime les deux colonnes.
 */
final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nomenclature INAMI : source et vérification de la date officielle d\'abrogation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nomenclature ADD inami_repeal_source VARCHAR(255) DEFAULT NULL, ADD inami_repeal_verified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nomenclature DROP inami_repeal_source, DROP inami_repeal_verified_at');
    }
}
