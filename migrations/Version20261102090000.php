<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Firmes partenaires par emplacement (docs/PARTENAIRES.md). Additif uniquement : aucune
 * colonne ni ligne existante n'est modifiée ou supprimée.
 *
 * - partner_placement_assignment : firme affichée sur chacun des six emplacements, par période ;
 * - reprise de l'existant : le partenaire publié en cours ou à venir (BOSI aujourd'hui) reçoit
 *   six affectations aux mêmes dates : l'affichage est identique après la migration ;
 * - partner_event : impressions réellement vues et clics des emplacements partenaires ;
 * - partner.cover_image : visuel de présentation de la firme ;
 * - marketing.professionals_only : campagne réservée aux utilisateurs connectés.
 *
 * Les colonnes de période de la table partner (ancien modèle) sont conservées : retour arrière
 * possible vers la version précédente sans perte.
 */
final class Version20261102090000 extends AbstractMigration
{
    private const PLACEMENTS = ['login_fallback', 'dashboard_partner', 'sidebar', 'mobile_menu', 'partners_page', 'public_home'];

    public function getDescription(): string
    {
        return 'Firmes partenaires par emplacement, mesure des emplacements partenaires, campagnes réservées aux professionnels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE partner_placement_assignment (id INT AUTO_INCREMENT NOT NULL, placement VARCHAR(40) NOT NULL, starts_on DATE DEFAULT NULL, ends_on DATE DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, withdrawn_at DATETIME DEFAULT NULL, partner_id INT DEFAULT NULL, INDEX idx_partner_placement_period (placement, status, starts_on, ends_on), INDEX IDX_8EAC41039393F8FE (partner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE partner_placement_assignment ADD CONSTRAINT FK_8EAC41039393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE RESTRICT');
        $this->addSql('CREATE TABLE partner_event (id INT AUTO_INCREMENT NOT NULL, placement VARCHAR(40) NOT NULL, kind VARCHAR(20) NOT NULL, viewer_hash VARCHAR(64) DEFAULT NULL, viewed_at DATETIME NOT NULL, partner_id INT NOT NULL, INDEX idx_partner_event_stats (partner_id, kind, viewed_at), INDEX idx_partner_event_date (viewed_at), INDEX IDX_2892A6C29393F8FE (partner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE partner_event ADD CONSTRAINT FK_2892A6C29393F8FE FOREIGN KEY (partner_id) REFERENCES partner (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE partner ADD cover_image VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE marketing ADD professionals_only TINYINT DEFAULT 0 NOT NULL');

        // Partenaire publié, en cours ou à venir (la veille incluse : fuseau du serveur) : mêmes
        // dates sur les six emplacements.
        $keys = implode(' UNION ALL ', array_map(static fn (string $key) => "SELECT '$key' AS placement", self::PLACEMENTS));
        $this->addSql(
            "INSERT INTO partner_placement_assignment (partner_id, placement, starts_on, ends_on, status, created_at, updated_at, published_at)
             SELECT p.id, k.placement, p.starts_on, p.ends_on, 'published', NOW(), NOW(), COALESCE(p.published_at, NOW())
             FROM partner p CROSS JOIN ($keys) k
             WHERE p.status = 'published' AND p.starts_on IS NOT NULL AND p.logo_id IS NOT NULL
               AND (p.ends_on IS NULL OR p.ends_on >= CURDATE() - INTERVAL 1 DAY)
             ORDER BY p.starts_on, p.id"
        );
    }

    public function down(Schema $schema): void
    {
        // Le partenaire reste décrit par ses colonnes historiques (inchangées) : rien à restaurer.
        $this->addSql('ALTER TABLE partner_event DROP FOREIGN KEY FK_2892A6C29393F8FE');
        $this->addSql('ALTER TABLE partner_placement_assignment DROP FOREIGN KEY FK_8EAC41039393F8FE');
        $this->addSql('DROP TABLE partner_event');
        $this->addSql('DROP TABLE partner_placement_assignment');
        $this->addSql('ALTER TABLE partner DROP cover_image');
        $this->addSql('ALTER TABLE marketing DROP professionals_only');
    }
}
