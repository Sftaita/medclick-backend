<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espaces sponsors (docs/SPONSORS.md) : emplacements, annonceur, contenus, priorité, exclusivité
 * des campagnes ; impressions et clics mesurés dans marketing_view.
 *
 * Aucune campagne ni statistique supprimée :
 * - campagnes existantes : emplacement « login_interstitial » (plein écran à la connexion,
 *   leur affichage actuel), priorité 5, non exclusives ;
 * - lignes existantes de marketing_view : « legacy_view » (comptées à chaque appel de l'API,
 *   pas à l'affichage réel) : conservées et présentées à part, jamais comme des impressions.
 */
final class Version20261101090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espaces sponsors : emplacements et contenus des campagnes, impressions et clics mesurés';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketing ADD sponsor_name VARCHAR(120) DEFAULT NULL, ADD offer VARCHAR(20) DEFAULT NULL, ADD title VARCHAR(120) DEFAULT NULL, ADD description VARCHAR(280) DEFAULT NULL, ADD logo VARCHAR(80) DEFAULT NULL, ADD banner_desktop VARCHAR(500) DEFAULT NULL, ADD banner_mobile VARCHAR(500) DEFAULT NULL, ADD show_cta TINYINT DEFAULT 0 NOT NULL, ADD placements JSON DEFAULT NULL, ADD priority SMALLINT DEFAULT 5 NOT NULL, ADD exclusive TINYINT DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE marketing SET placements = '[\"login_interstitial\"]'");
        $this->addSql('ALTER TABLE marketing MODIFY placements JSON NOT NULL');

        $this->addSql("ALTER TABLE marketing_view ADD kind VARCHAR(20) DEFAULT 'impression' NOT NULL, ADD placement VARCHAR(30) DEFAULT 'login_interstitial' NOT NULL, ADD viewer_hash VARCHAR(64) DEFAULT NULL");
        $this->addSql("UPDATE marketing_view SET kind = 'legacy_view'");
        $this->addSql('CREATE INDEX idx_marketing_view_stats ON marketing_view (marketing_id, kind, viewed_at)');
        $this->addSql('CREATE INDEX idx_marketing_view_date ON marketing_view (viewed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_marketing_view_stats ON marketing_view');
        $this->addSql('DROP INDEX idx_marketing_view_date ON marketing_view');
        // Impressions et clics mesurés : supprimés (seules les vues historiques existaient avant).
        $this->addSql("DELETE FROM marketing_view WHERE kind <> 'legacy_view'");
        $this->addSql('ALTER TABLE marketing_view DROP kind, DROP placement, DROP viewer_hash');
        $this->addSql('ALTER TABLE marketing DROP sponsor_name, DROP offer, DROP title, DROP description, DROP logo, DROP banner_desktop, DROP banner_mobile, DROP show_cta, DROP placements, DROP priority, DROP exclusive');
    }
}
