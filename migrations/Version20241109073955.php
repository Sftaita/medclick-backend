<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241109073955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE marketing DROP delay, DROP marketing_link');
        $this->addSql('ALTER TABLE user ADD accepted_terms TINYINT(1) DEFAULT NULL, ADD terms_accepted_date DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP accepted_terms, DROP terms_accepted_date');
        $this->addSql('ALTER TABLE marketing ADD delay INT NOT NULL, ADD marketing_link VARCHAR(500) DEFAULT NULL');
    }
}
