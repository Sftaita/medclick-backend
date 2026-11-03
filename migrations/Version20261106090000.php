<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Classement historique au récapitulatif Excel (ADR-211), distinct de l'autorisation de saisie.
 *
 * Additif uniquement : une nouvelle table, aucune ligne existante modifiée (nomenclature, interventions
 * et favoris inchangés). Compatible avec la version en service (elle ignore la table).
 * Retour arrière : down() supprime la table (les interventions gardent leur spécialité).
 */
final class Version20261106090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nomenclature : classement historique des interventions par spécialité (récapitulatif Excel)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE nomenclature_historical_speciality (id INT AUTO_INCREMENT NOT NULL, nomenclature_id INT NOT NULL, speciality VARCHAR(50) NOT NULL, INDEX IDX_AE11F4FE90BFD4B8 (nomenclature_id), UNIQUE INDEX uniq_nomenclature_historical_speciality (nomenclature_id, speciality), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE nomenclature_historical_speciality ADD CONSTRAINT FK_AE11F4FE90BFD4B8 FOREIGN KEY (nomenclature_id) REFERENCES nomenclature (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nomenclature_historical_speciality DROP FOREIGN KEY FK_AE11F4FE90BFD4B8');
        $this->addSql('DROP TABLE nomenclature_historical_speciality');
    }
}
