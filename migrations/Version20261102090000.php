<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Additif uniquement : les partenaires et leurs périodes historiques sont conservés.
 * Tant que les affectations ne sont pas publiées, le partenaire historique sert de repli.
 */
final class Version20261102090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Affectations indépendantes des partenaires par emplacement, sans modifier BOSI';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE partner_placement_assignment (id INT AUTO_INCREMENT NOT NULL, partner_id INT DEFAULT NULL, placement VARCHAR(40) NOT NULL, starts_on DATE DEFAULT NULL, ends_on DATE DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', withdrawn_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_PARTNER_PLACEMENT_PARTNER (partner_id), INDEX idx_partner_placement_period (placement, status, starts_on, ends_on), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE partner_placement_assignment ADD CONSTRAINT FK_PARTNER_PLACEMENT_PARTNER FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE partner_placement_assignment');
    }
}
