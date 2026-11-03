<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Actes partagés entre spécialités (ADR-211) : spécialités SUPPLÉMENTAIRES autorisées pour un code.
 *
 * Additif uniquement : une nouvelle table, aucune ligne existante modifiée (nomenclature,
 * interventions et favoris inchangés). Compatible avec la version en service (elle ignore la table).
 * Un code INAMI garde une seule ligne dans `nomenclature`.
 * Retour arrière : down() supprime la table (les interventions déjà enregistrées gardent leur spécialité).
 */
final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nomenclature : spécialités supplémentaires autorisées par code (actes partagés)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE nomenclature_shared_speciality (id INT AUTO_INCREMENT NOT NULL, nomenclature_id INT NOT NULL, speciality VARCHAR(50) NOT NULL, INDEX IDX_7A1A095090BFD4B8 (nomenclature_id), INDEX idx_nomenclature_shared_speciality_speciality (speciality), UNIQUE INDEX uniq_nomenclature_shared_speciality (nomenclature_id, speciality), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE nomenclature_shared_speciality ADD CONSTRAINT FK_7A1A095090BFD4B8 FOREIGN KEY (nomenclature_id) REFERENCES nomenclature (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nomenclature_shared_speciality DROP FOREIGN KEY FK_7A1A095090BFD4B8');
        $this->addSql('DROP TABLE nomenclature_shared_speciality');
    }
}
