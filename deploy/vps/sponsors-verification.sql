-- Vérification de la migration des espaces sponsors (Version20261101090000), lecture seule.
-- À exécuter AVANT puis APRÈS la migration (et après son retour arrière), sur la préproduction
-- (copie récente de production, MariaDB 11.8) puis en production : les empreintes doivent être identiques.
-- Usage : docker compose ... exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" --force' < sponsors-verification.sql
--
-- Empreinte sans GROUP_CONCAT (tronqué sans erreur au-delà de group_concat_max_len) : MD5 de chaque
-- ligne (toutes les colonnes de la 3.2.0, NULL distingué), combiné par BIT_XOR sur 128 bits,
-- avec le nombre de lignes et le total d'octets. Indépendant de l'ordre ; identifiants uniques,
-- donc aucune ligne ne peut en annuler une autre.

-- 1. Empreintes des données existantes.
SELECT t, n, CONCAT(LPAD(HEX(x1), 16, '0'), LPAD(HEX(x2), 16, '0')) AS empreinte, octets FROM (
  SELECT 'marketing' AS t, COUNT(*) AS n, BIT_XOR(CAST(CONV(SUBSTRING(h, 1, 16), 16, 10) AS UNSIGNED)) AS x1,
    BIT_XOR(CAST(CONV(SUBSTRING(h, 17, 16), 16, 10) AS UNSIGNED)) AS x2, SUM(l) AS octets
  FROM (SELECT MD5(r) AS h, LENGTH(r) AS l FROM (SELECT CONCAT_WS('|', id, campaign_name, status, start_date,
      IFNULL(end_date, '\N'), views, clicks, IFNULL(smartphone_format, '\N'), IFNULL(tablet_portrait_format, '\N'),
      IFNULL(tablet_landscape_format, '\N'), IFNULL(screen_14_inch_format, '\N'), IFNULL(large_screen_format, '\N'),
      IFNULL(duration, '\N'), IFNULL(redirect_url, '\N')) AS r FROM marketing) a) b
  UNION ALL
  SELECT 'marketing_view', COUNT(*), BIT_XOR(CAST(CONV(SUBSTRING(h, 1, 16), 16, 10) AS UNSIGNED)),
    BIT_XOR(CAST(CONV(SUBSTRING(h, 17, 16), 16, 10) AS UNSIGNED)), SUM(l)
  FROM (SELECT MD5(r) AS h, LENGTH(r) AS l FROM (SELECT CONCAT_WS('|', id, marketing_id, viewed_at) AS r FROM marketing_view) a) b
  UNION ALL
  SELECT 'marketing_image', COUNT(*), BIT_XOR(CAST(CONV(SUBSTRING(h, 1, 16), 16, 10) AS UNSIGNED)),
    BIT_XOR(CAST(CONV(SUBSTRING(h, 17, 16), 16, 10) AS UNSIGNED)), SUM(l)
  FROM (SELECT MD5(r) AS h, LENGTH(r) AS l FROM (SELECT CONCAT_WS('|', id, sha256, width, height, size, original_size,
      MD5(data), LENGTH(data), created_at) AS r FROM marketing_image) a) b
  UNION ALL
  SELECT 'partner', COUNT(*), BIT_XOR(CAST(CONV(SUBSTRING(h, 1, 16), 16, 10) AS UNSIGNED)),
    BIT_XOR(CAST(CONV(SUBSTRING(h, 17, 16), 16, 10) AS UNSIGNED)), SUM(l)
  FROM (SELECT MD5(r) AS h, LENGTH(r) AS l FROM (SELECT CONCAT_WS('|', id, name, status, IFNULL(starts_on, '\N'),
      IFNULL(ends_on, '\N'), IFNULL(logo_id, '\N'), IFNULL(website_url, '\N'), IFNULL(tagline, '\N'), IFNULL(about, '\N'),
      commitments, created_at, updated_at) AS r FROM partner) a) b
) e;

-- 2. Après la migration seulement (erreur « colonne inconnue » avant : normal).
--    Attendu : toutes les campagnes existantes en plein écran, priorité 5, non exclusives ;
--    toutes les lignes existantes de marketing_view en legacy_view, sans empreinte de visiteur.
SELECT placements, priority, exclusive, show_cta, COUNT(*) AS campagnes FROM marketing GROUP BY placements, priority, exclusive, show_cta;
SELECT kind, placement, COUNT(*) AS lignes, COUNT(viewer_hash) AS empreintes FROM marketing_view GROUP BY kind, placement;
