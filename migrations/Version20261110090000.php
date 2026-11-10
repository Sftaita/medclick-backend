<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Campagnes e-mail (docs/CAMPAGNES-EMAIL.md). Additif : deux tables nouvelles, rien d'existant
 * n'est modifié.
 *
 * - email_campaign_send : journal des envois (tests et campagne), clé unique campagne + adresse
 *   (aucun second envoi), jeton de désinscription propre à chaque envoi ;
 * - email_unsubscribe : liste d'opposition (désinscriptions), une ligne par adresse.
 */
final class Version20261110090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campagnes e-mail : journal des envois et désinscriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_campaign_send (id INT AUTO_INCREMENT NOT NULL, campaign VARCHAR(60) NOT NULL, kind VARCHAR(10) NOT NULL, email VARCHAR(180) NOT NULL, dedup_key VARCHAR(40) DEFAULT NULL, unsubscribe_token VARCHAR(64) NOT NULL, status VARCHAR(10) NOT NULL, error VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, requested_by INT DEFAULT NULL, INDEX idx_email_campaign_send_campaign (campaign, kind, status), UNIQUE INDEX uniq_email_campaign_send_dedup (dedup_key), UNIQUE INDEX uniq_email_campaign_send_token (unsubscribe_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_unsubscribe (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, source VARCHAR(20) NOT NULL, campaign VARCHAR(60) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_email_unsubscribe_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_unsubscribe');
        $this->addSql('DROP TABLE email_campaign_send');
    }
}
