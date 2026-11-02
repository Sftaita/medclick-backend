<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étape 4 nomenclature INAMI : statut actif / inactif des codes (ADR-210, docs/DECISIONS.md).
 *
 * Additif uniquement : trois colonnes ajoutées à `nomenclature`, aucune ligne supprimée,
 * renumérotée ni modifiée en dehors de ces colonnes.
 *
 * - active : TRUE par défaut, donc les codes existants restent tous actifs après la migration ;
 * - inami_repealed_at : date OFFICIELLE d'abrogation publiée par l'INAMI (facultative) ;
 * - deactivated_at : date et heure TECHNIQUES de la désactivation dans MedClick.
 *
 * Index (speciality, active) : liste des codes actifs d'une spécialité (/api/nomenclature/{speciality}).
 * Retour arrière : down() supprime les trois colonnes et l'index (aucune autre donnée touchée).
 */
final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nomenclature INAMI : statut actif / inactif, date officielle d\'abrogation et date technique de désactivation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nomenclature ADD active TINYINT DEFAULT 1 NOT NULL, ADD inami_repealed_at DATE DEFAULT NULL, ADD deactivated_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_nomenclature_speciality_active ON nomenclature (speciality, active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_nomenclature_speciality_active ON nomenclature');
        $this->addSql('ALTER TABLE nomenclature DROP active, DROP inami_repealed_at, DROP deactivated_at');
    }
}
