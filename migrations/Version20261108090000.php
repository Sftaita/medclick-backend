<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recherche publique des codes INAMI : compteurs quotidiens des visites et des spécialités
 * consultées (App\Entity\InamiSearchStat). Aucune donnée nominative, aucun terme de recherche.
 *
 * Indispensable : les statistiques demandées (visites, spécialités) doivent être conservées, et
 * aucune table existante n'a ce rôle (marketing_view ne mesure que des campagnes).
 * Additif uniquement : une nouvelle table, aucune table existante modifiée. Compatible avec la
 * version en service. Retour arrière : down() supprime la table (statistiques perdues).
 */
final class Version20261108090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recherche INAMI : statistiques quotidiennes agrégées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE inami_search_stat (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, kind VARCHAR(20) NOT NULL, speciality VARCHAR(50) DEFAULT '' NOT NULL, count INT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_inami_search_stat (day, kind, speciality), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inami_search_stat');
    }
}
