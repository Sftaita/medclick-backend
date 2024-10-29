<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241022040208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE marketing (id INT AUTO_INCREMENT NOT NULL, campaign_name VARCHAR(400) NOT NULL, status VARCHAR(255) NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, views INT DEFAULT 0 NOT NULL, clicks INT DEFAULT 0 NOT NULL, smartphone_format VARCHAR(500) DEFAULT NULL, tablet_portrait_format VARCHAR(500) DEFAULT NULL, tablet_landscape_format VARCHAR(500) DEFAULT NULL, screen_14_inch_format VARCHAR(500) DEFAULT NULL, large_screen_format VARCHAR(500) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE marketing');
    }
}
