<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Administration « Publicités & partenaires » par emplacement (docs/PUBLICITES-PARTENAIRES.md).
 * Additif : aucune ligne existante n'est supprimée, aucune colonne n'est retirée.
 *
 * - ad_placement_setting : un réglage par emplacement (activé, fréquence du plein écran, ordre
 *   des publicités), tous activés : l'affichage est identique après la migration ;
 * - marketing.partner_id : entreprise de chaque publicité. Reprise de l'existant : rattachement
 *   par le nom de l'annonceur (sponsor_name) à l'entreprise du même nom, sans tenir compte de la
 *   casse ni des espaces ; une entreprise est créée (une seule par nom) pour un annonceur qui
 *   n'en a pas encore. Les publicités sans annonceur restent sans entreprise ;
 * - marketing.pending_changes, partner_placement_assignment.pending_changes : modifications
 *   enregistrées mais pas encore publiées ;
 * - partner.contact_* : contact administratif facultatif de l'entreprise.
 */
final class Version20261105090000 extends AbstractMigration
{
    private const EMPLACEMENTS = ['login', 'after_login', 'dashboard_carousel', 'dashboard_partner', 'sidebar', 'mobile_menu', 'public_home', 'partners_page'];

    public function getDescription(): string
    {
        return 'Emplacements publicitaires : réglages, entreprise des publicités, modifications en attente, contact des entreprises';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ad_placement_setting (emplacement VARCHAR(40) NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, frequency VARCHAR(20) DEFAULT NULL, display_order JSON NOT NULL, pending_order JSON DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (emplacement)) DEFAULT CHARACTER SET utf8mb4');
        foreach (self::EMPLACEMENTS as $key) {
            // Plein écran : une fois par jour et par publicité, comme jusqu'ici.
            $frequency = $key === 'after_login' ? "'daily'" : 'NULL';
            $this->addSql("INSERT INTO ad_placement_setting (emplacement, enabled, frequency, display_order, updated_at) VALUES ('$key', 1, $frequency, '[]', NOW())");
        }

        $this->addSql('ALTER TABLE marketing ADD partner_id INT DEFAULT NULL, ADD pending_changes JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE marketing ADD CONSTRAINT FK_948161689393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_948161689393F8FE ON marketing (partner_id)');
        $this->addSql('ALTER TABLE partner_placement_assignment ADD pending_changes JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE partner ADD contact_name VARCHAR(120) DEFAULT NULL, ADD contact_email VARCHAR(180) DEFAULT NULL, ADD contact_phone VARCHAR(40) DEFAULT NULL');

        // Annonceurs sans entreprise : une entreprise par nom (sans doublon de casse ni d'espaces).
        $this->addSql(
            "INSERT INTO partner (name, commitments, status, created_at, updated_at)
             SELECT MIN(TRIM(m.sponsor_name)), '[]', 'draft', NOW(), NOW()
             FROM marketing m
             WHERE m.sponsor_name IS NOT NULL AND TRIM(m.sponsor_name) <> ''
               AND NOT EXISTS (SELECT 1 FROM partner p WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(m.sponsor_name)))
             GROUP BY LOWER(TRIM(m.sponsor_name))"
        );
        // Plusieurs fiches du même nom (anciennes reprogrammations) : la plus ancienne.
        $this->addSql(
            "UPDATE marketing m
             SET m.partner_id = (SELECT MIN(p.id) FROM partner p WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(m.sponsor_name)))
             WHERE m.sponsor_name IS NOT NULL AND TRIM(m.sponsor_name) <> ''"
        );
    }

    public function down(Schema $schema): void
    {
        // Les entreprises créées pour les annonceurs sont conservées (fiches sans diffusion,
        // ignorées par la version précédente).
        $this->addSql('ALTER TABLE marketing DROP FOREIGN KEY FK_948161689393F8FE');
        $this->addSql('DROP INDEX IDX_948161689393F8FE ON marketing');
        $this->addSql('ALTER TABLE marketing DROP partner_id, DROP pending_changes');
        $this->addSql('ALTER TABLE partner_placement_assignment DROP pending_changes');
        $this->addSql('ALTER TABLE partner DROP contact_name, DROP contact_email, DROP contact_phone');
        $this->addSql('DROP TABLE ad_placement_setting');
    }
}
