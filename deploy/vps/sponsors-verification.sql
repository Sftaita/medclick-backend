-- Vérification de la migration des espaces sponsors (Version20261101090000), lecture seule.
-- À exécuter AVANT puis APRÈS la migration, sur la préproduction (copie fraîche de production,
-- MariaDB 11.8) puis en production : les trois empreintes doivent être identiques.
-- Usage : docker compose ... exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < sponsors-verification.sql

-- 1. Empreinte des données existantes (colonnes de la 3.2.0 uniquement).
SELECT 'marketing' AS t, COUNT(*) AS n, MD5(GROUP_CONCAT(CONCAT_WS('|', id, campaign_name, status, start_date, IFNULL(end_date, '-'), views, clicks,
  IFNULL(smartphone_format, '-'), IFNULL(tablet_portrait_format, '-'), IFNULL(tablet_landscape_format, '-'),
  IFNULL(screen_14_inch_format, '-'), IFNULL(large_screen_format, '-'), duration, IFNULL(redirect_url, '-')) ORDER BY id SEPARATOR '#')) AS empreinte FROM marketing
UNION ALL
SELECT 'marketing_view', COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|', id, marketing_id, viewed_at) ORDER BY id SEPARATOR '#')) FROM marketing_view
UNION ALL
SELECT 'marketing_image', COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|', id, sha256, width, height, size, original_size, MD5(data), created_at) ORDER BY id SEPARATOR '#')) FROM marketing_image
UNION ALL
SELECT 'partner', COUNT(*), MD5(GROUP_CONCAT(CONCAT_WS('|', id, name, status, IFNULL(starts_on, '-'), IFNULL(ends_on, '-'), IFNULL(logo_id, '-')) ORDER BY id SEPARATOR '#')) FROM partner;

-- 2. Après la migration seulement (erreur « colonne inconnue » avant : normal).
--    Attendu : toutes les campagnes existantes en plein écran, priorité 5, non exclusives ;
--    toutes les lignes existantes de marketing_view en legacy_view, sans empreinte de visiteur.
SELECT placements, priority, exclusive, show_cta, COUNT(*) AS campagnes FROM marketing GROUP BY placements, priority, exclusive, show_cta;
SELECT kind, placement, COUNT(*) AS lignes, COUNT(viewer_hash) AS empreintes FROM marketing_view GROUP BY kind, placement;
