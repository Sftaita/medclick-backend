<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Visuels importés des campagnes : table marketing_image (WebP stockés en base : inclus dans les
 * sauvegardes). Ajout de table uniquement : les colonnes *_format de marketing et leurs URL
 * externes sont conservées telles quelles. SQL généré par Doctrine pour MariaDB 11.8.
 */
final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table marketing_image (visuels importés des campagnes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketing_image (id INT AUTO_INCREMENT NOT NULL, sha256 VARCHAR(64) NOT NULL, width INT NOT NULL, height INT NOT NULL, size INT NOT NULL, original_size INT NOT NULL, data LONGBLOB NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_marketing_image_sha256 (sha256), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketing_image');
    }
}
