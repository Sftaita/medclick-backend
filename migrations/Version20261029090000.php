<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Surveillance croisée des exports Excel (docs/EXPORTS.md) : le worker signale un gardien planifié
 * qui ne passe plus. Ajout de colonnes à export_monitor_state uniquement (table de la surveillance,
 * une ligne, aucune donnée personnelle).
 */
final class Version20261029090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'export_monitor_state : alerte « gardien des exports inactif » (envoyée par le worker)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE export_monitor_state ADD watchdog_alert_status VARCHAR(20) NOT NULL, ADD watchdog_alert_attempts INT NOT NULL, ADD watchdog_alert_last_attempt_at DATETIME DEFAULT NULL, ADD watchdog_alert_sent_at DATETIME DEFAULT NULL, ADD watchdog_alert_last_error VARCHAR(500) DEFAULT NULL');
        // Ligne existante : aucune alerte en cours.
        $this->addSql("UPDATE export_monitor_state SET watchdog_alert_status = 'none', watchdog_alert_attempts = 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE export_monitor_state DROP watchdog_alert_status, DROP watchdog_alert_attempts, DROP watchdog_alert_last_attempt_at, DROP watchdog_alert_sent_at, DROP watchdog_alert_last_error');
    }
}
