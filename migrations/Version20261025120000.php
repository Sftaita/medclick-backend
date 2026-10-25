<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module partenaire : tables partner (partenaires affichés, historique) et partner_logo
 * (logos PNG/SVG stockés en base : inclus dans les sauvegardes). Ajout de tables uniquement.
 * SQL généré par Doctrine pour MariaDB 11.8 (plateforme de production).
 */
final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée les tables partner et partner_logo (module partenaire)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE partner_logo (id INT AUTO_INCREMENT NOT NULL, sha256 VARCHAR(64) NOT NULL, mime_type VARCHAR(40) NOT NULL, size INT NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, data LONGBLOB NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_partner_logo_sha256 (sha256), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE partner (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, website_url VARCHAR(500) DEFAULT NULL, tagline VARCHAR(160) DEFAULT NULL, about LONGTEXT DEFAULT NULL, commitments JSON NOT NULL, starts_on DATE DEFAULT NULL, ends_on DATE DEFAULT NULL, status VARCHAR(20) NOT NULL, published_at DATETIME DEFAULT NULL, withdrawn_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, logo_id INT DEFAULT NULL, INDEX idx_partner_period (status, starts_on, ends_on), INDEX IDX_312B3E16F98F144A (logo_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE partner ADD CONSTRAINT FK_312B3E16F98F144A FOREIGN KEY (logo_id) REFERENCES partner_logo (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE partner DROP FOREIGN KEY FK_312B3E16F98F144A');
        $this->addSql('DROP TABLE partner');
        $this->addSql('DROP TABLE partner_logo');
    }
}
