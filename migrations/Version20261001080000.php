<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table error_log : erreurs rencontrées par les utilisateurs (API, console, fronts).
 * SQL généré par Doctrine pour MariaDB 11.8 (plateforme de production).
 */
final class Version20261001080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table error_log (capture des erreurs rencontrées par les utilisateurs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE error_log (id INT AUTO_INCREMENT NOT NULL, fingerprint VARCHAR(40) NOT NULL, source VARCHAR(20) NOT NULL, message LONGTEXT NOT NULL, exception_class VARCHAR(255) DEFAULT NULL, file VARCHAR(255) DEFAULT NULL, line INT DEFAULT NULL, trace LONGTEXT DEFAULT NULL, method VARCHAR(10) DEFAULT NULL, path VARCHAR(500) DEFAULT NULL, status_code INT DEFAULT NULL, user_id INT DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, context JSON DEFAULT NULL, occurrences INT NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_FCDF27A9FC0B754A (fingerprint), INDEX idx_error_log_last_seen (last_seen_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE error_log');
    }
}
