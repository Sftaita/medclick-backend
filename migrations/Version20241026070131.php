<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241026070131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE marketing_view (id INT AUTO_INCREMENT NOT NULL, marketing_id INT NOT NULL, viewed_at DATETIME NOT NULL, INDEX IDX_7CDA49D6C6DCB66C (marketing_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE marketing_view ADD CONSTRAINT FK_7CDA49D6C6DCB66C FOREIGN KEY (marketing_id) REFERENCES marketing (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE marketing_view DROP FOREIGN KEY FK_7CDA49D6C6DCB66C');
        $this->addSql('DROP TABLE marketing_view');
    }
}
