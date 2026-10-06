-- Empreinte déterministe de toutes les tables de la base courante (aucune donnée affichée) :
-- table, lignes, MIN/MAX(id) si la colonne existe, SUM et BIT_XOR des CRC32 de chaque ligne
-- (toutes colonnes, NULL distingué). Même requête des deux côtés → résultats comparables.
SET SESSION group_concat_max_len = 4194304;
SELECT GROUP_CONCAT(q ORDER BY tn SEPARATOR ' UNION ALL ') INTO @fp FROM (
  SELECT t.table_name AS tn, CONCAT(
    'SELECT ''', t.table_name, ''' AS t, COUNT(*) AS n, ',
    IF(SUM(c.column_name = 'id') > 0, 'MIN(id) AS min_id, MAX(id) AS max_id', 'NULL AS min_id, NULL AS max_id'),
    ', COALESCE(SUM(CRC32(r)), 0) AS crc_sum, COALESCE(BIT_XOR(CRC32(r)), 0) AS crc_xor FROM (SELECT ',
    IF(SUM(c.column_name = 'id') > 0, 'id, ', ''),
    'CONCAT_WS(''|'', ',
    GROUP_CONCAT(CONCAT('IFNULL(CAST(`', c.column_name, '` AS CHAR), ''<NULL>'')') ORDER BY c.ordinal_position SEPARATOR ', '),
    ') AS r FROM `', t.table_name, '`) x') AS q
  FROM information_schema.tables t
  JOIN information_schema.columns c ON c.table_schema = t.table_schema AND c.table_name = t.table_name
  WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
  GROUP BY t.table_name
) s;
PREPARE fp FROM @fp;
EXECUTE fp;
DEALLOCATE PREPARE fp;
