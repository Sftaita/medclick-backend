<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gardien des exports Excel (docs/EXPORTS.md) : état de la surveillance partagé par l'API, le
 * worker et le gardien planifié sur le serveur (présence du worker, alerte d'absence, bail).
 * Ajout d'une table uniquement (une ligne, créée à la première utilisation), aucune donnée
 * existante modifiée.
 */
final class Version20261028090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée export_monitor_state (présence du worker des exports, gardien planifié)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE export_monitor_state (id INT NOT NULL, worker_seen_at DATETIME DEFAULT NULL, worker_absent_since DATETIME DEFAULT NULL, worker_alert_status VARCHAR(20) NOT NULL, worker_alert_attempts INT NOT NULL, worker_alert_last_attempt_at DATETIME DEFAULT NULL, worker_alert_sent_at DATETIME DEFAULT NULL, worker_alert_last_error VARCHAR(500) DEFAULT NULL, last_run_at DATETIME DEFAULT NULL, last_run_by VARCHAR(20) DEFAULT NULL, last_watchdog_at DATETIME DEFAULT NULL, run_lease_until DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE export_monitor_state');
    }
}
