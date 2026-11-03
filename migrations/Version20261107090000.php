<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nomenclature (ADR-212) : justification d'une désactivation ADMINISTRATIVE, distincte d'une abrogation
 * officielle INAMI (`inami_repealed_at`, `inami_repeal_source`, `inami_repeal_verified_at`).
 *
 * Additif uniquement : une colonne facultative, aucune ligne modifiée. Compatible avec la version en service.
 * Retour arrière : down() supprime la colonne, après le retour arrière du plan de désactivation.
 */
final class Version20261107090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nomenclature : justification des désactivations administratives';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nomenclature ADD deactivation_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nomenclature DROP deactivation_reason');
    }
}
