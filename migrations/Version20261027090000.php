<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exports Excel asynchrones (docs/EXPORTS.md) : historique des exports (export_job), de leurs
 * évènements (export_job_event) et file de messages du worker (messenger_messages, transport
 * Doctrine de Symfony Messenger). Ajout de tables uniquement, aucune donnée existante modifiée.
 * SQL généré par Doctrine pour MariaDB 11.8.
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée export_job, export_job_event et messenger_messages (exports Excel asynchrones)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE export_job (id INT AUTO_INCREMENT NOT NULL, public_id VARCHAR(36) NOT NULL, correlation_id VARCHAR(32) NOT NULL, user_id INT NOT NULL, type VARCHAR(40) NOT NULL, parameters JSON NOT NULL, status VARCHAR(20) NOT NULL, step VARCHAR(20) NOT NULL, processed INT DEFAULT NULL, total INT DEFAULT NULL, row_count INT DEFAULT NULL, requested_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, duration_ms INT DEFAULT NULL, heartbeat_at DATETIME DEFAULT NULL, attempts INT NOT NULL, cancel_requested TINYINT NOT NULL, file_name VARCHAR(64) DEFAULT NULL, file_size INT DEFAULT NULL, file_sha256 VARCHAR(64) DEFAULT NULL, expires_at DATETIME DEFAULT NULL, file_deleted_at DATETIME DEFAULT NULL, download_attempts INT NOT NULL, first_served_at DATETIME DEFAULT NULL, first_downloaded_at DATETIME DEFAULT NULL, not_downloaded_at DATETIME DEFAULT NULL, not_downloaded_alerted_at DATETIME DEFAULT NULL, error_category VARCHAR(30) DEFAULT NULL, error_code VARCHAR(255) DEFAULT NULL, error_message VARCHAR(500) DEFAULT NULL, error_step VARCHAR(20) DEFAULT NULL, alert_status VARCHAR(20) NOT NULL, alert_fingerprint VARCHAR(40) DEFAULT NULL, alert_attempts INT NOT NULL, alert_last_attempt_at DATETIME DEFAULT NULL, alert_sent_at DATETIME DEFAULT NULL, alert_last_error VARCHAR(500) DEFAULT NULL, alert_duplicate_of VARCHAR(36) DEFAULT NULL, UNIQUE INDEX UNIQ_93DEA7C8B5B48B91 (public_id), INDEX idx_export_job_user (user_id, requested_at), INDEX idx_export_job_status (status, requested_at), INDEX idx_export_job_requested (requested_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE export_job_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, type VARCHAR(30) NOT NULL, step VARCHAR(20) DEFAULT NULL, message VARCHAR(500) DEFAULT NULL, context JSON DEFAULT NULL, job_id INT NOT NULL, INDEX IDX_B7F4F167BE04EA9 (job_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE export_job_event ADD CONSTRAINT FK_B7F4F167BE04EA9 FOREIGN KEY (job_id) REFERENCES export_job (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE export_job_event DROP FOREIGN KEY FK_B7F4F167BE04EA9');
        $this->addSql('DROP TABLE export_job_event');
        $this->addSql('DROP TABLE export_job');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
