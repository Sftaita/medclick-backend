<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durée d'affichage des campagnes à la connexion, désormais obligatoire : les campagnes
 * existantes sans durée (ou avec une durée invalide) reçoivent la valeur par défaut, 5 s.
 * Les durées valides déjà saisies (1 à 60 s) sont conservées.
 * SQL généré par Doctrine pour MariaDB 11.8 (plateforme de production).
 */
final class Version20261025180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campagnes : durée d\'affichage obligatoire (5 s par défaut pour les campagnes existantes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE marketing SET duration = 5 WHERE duration IS NULL OR duration < 1 OR duration > 60');
        $this->addSql('ALTER TABLE marketing CHANGE duration duration INT DEFAULT 5 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketing CHANGE duration duration INT DEFAULT 5');
    }
}
