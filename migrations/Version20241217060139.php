<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241217060139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE marketing (id INT AUTO_INCREMENT NOT NULL, campaign_name VARCHAR(400) NOT NULL, status VARCHAR(255) NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, views INT DEFAULT 0 NOT NULL, clicks INT DEFAULT 0 NOT NULL, smartphone_format VARCHAR(500) DEFAULT NULL, tablet_portrait_format VARCHAR(500) DEFAULT NULL, tablet_landscape_format VARCHAR(500) DEFAULT NULL, screen_14_inch_format VARCHAR(500) DEFAULT NULL, large_screen_format VARCHAR(500) DEFAULT NULL, duration INT DEFAULT 5, redirect_url VARCHAR(500) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE marketing_view (id INT AUTO_INCREMENT NOT NULL, marketing_id INT NOT NULL, viewed_at DATETIME NOT NULL, INDEX IDX_7CDA49D6C6DCB66C (marketing_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE terms_conditions (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, published_at DATE NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE marketing_view ADD CONSTRAINT FK_7CDA49D6C6DCB66C FOREIGN KEY (marketing_id) REFERENCES marketing (id)');
        $this->addSql('ALTER TABLE favorites ADD surgery_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_E46960F5B0B8EA83 FOREIGN KEY (surgery_id) REFERENCES nomenclature (id)');
        $this->addSql('CREATE INDEX IDX_E46960F5B0B8EA83 ON favorites (surgery_id)');
        $this->addSql('ALTER TABLE nomenclature CHANGE name name VARCHAR(1000) NOT NULL');
        $this->addSql('ALTER TABLE surgeries ADD nomenclature_id INT DEFAULT NULL, ADD created_at DATETIME DEFAULT NULL, CHANGE name name VARCHAR(1000) NOT NULL');
        $this->addSql('ALTER TABLE surgeries ADD CONSTRAINT FK_A3F5554B90BFD4B8 FOREIGN KEY (nomenclature_id) REFERENCES nomenclature (id)');
        $this->addSql('CREATE INDEX IDX_A3F5554B90BFD4B8 ON surgeries (nomenclature_id)');
        $this->addSql('ALTER TABLE user ADD accepted_terms TINYINT(1) DEFAULT NULL, ADD terms_accepted_date DATETIME DEFAULT NULL, CHANGE roles roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE marketing_view DROP FOREIGN KEY FK_7CDA49D6C6DCB66C');
        $this->addSql('DROP TABLE marketing');
        $this->addSql('DROP TABLE marketing_view');
        $this->addSql('DROP TABLE terms_conditions');
        $this->addSql('ALTER TABLE favorites DROP FOREIGN KEY FK_E46960F5B0B8EA83');
        $this->addSql('DROP INDEX IDX_E46960F5B0B8EA83 ON favorites');
        $this->addSql('ALTER TABLE favorites DROP surgery_id');
        $this->addSql('ALTER TABLE user DROP accepted_terms, DROP terms_accepted_date, CHANGE roles roles TEXT NOT NULL');
        $this->addSql('ALTER TABLE nomenclature CHANGE name name TEXT NOT NULL');
        $this->addSql('ALTER TABLE surgeries DROP FOREIGN KEY FK_A3F5554B90BFD4B8');
        $this->addSql('DROP INDEX IDX_A3F5554B90BFD4B8 ON surgeries');
        $this->addSql('ALTER TABLE surgeries DROP nomenclature_id, DROP created_at, CHANGE name name VARCHAR(255) NOT NULL');
    }
}
