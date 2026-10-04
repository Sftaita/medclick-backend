# LOT 2D.1 : interventions incohérentes avec leur nomenclature (détection, lecture seule)

## Contexte

Avant le correctif `SurgeryNomenclatureProcessor`, un `PUT /api/surgeries/{id}` (opération
API Platform utilisée par la PWA) mettait à jour `name` et `speciality` depuis le payload, mais
ignorait `surgeryId` : la relation `nomenclature` et le `code` restaient ceux de l'intervention
d'origine. Le carnet Excel (`/api/excel2`) affichait alors le code A à côté du nom B, et le
récapitulatif comptait l'intervention sous la nomenclature A.

Source de vérité à la création (`/api/surgeries/addNewSurgery`) : la nomenclature.
`code = codeHospitalisation . n`, `name = nomenclature.name`, `speciality = nomenclature.speciality`.

## Requêtes (SELECT uniquement, à exécuter sur une copie ou en lecture seule)

Aucune donnée personnelle n'est sélectionnée : uniquement des compteurs et des identifiants
techniques. Ne rien modifier sur la base à partir de ces résultats sans décision séparée.

### 1. Vue d'ensemble par type d'incohérence

```sql
SELECT
  COUNT(*)                                                                         AS total,
  SUM(s.nomenclature_id IS NULL)                                                   AS sans_nomenclature,
  SUM(n.id IS NOT NULL AND COALESCE(s.code, '') <>
      CONCAT(COALESCE(n.code_hospitalisation, ''), COALESCE(n.n, '')))             AS code_different,
  SUM(n.id IS NOT NULL AND s.name <> n.name)                                       AS nom_different,
  SUM(n.id IS NOT NULL AND s.speciality <> n.speciality)                           AS specialite_differente,
  SUM(s.speciality = 'favorites')                                                  AS specialite_favorites
FROM surgeries s
LEFT JOIN nomenclature n ON n.id = s.nomenclature_id;
```

### 2. Signature du bug du PUT

Code toujours cohérent avec la relation (A), mais nom égal à celui d'une **autre** entrée de la
nomenclature (B) : l'utilisateur a changé d'intervention après la création.

```sql
SELECT s.id, s.nomenclature_id AS nomenclature_actuelle, s.code,
       MIN(b.id) AS nomenclature_probable, COUNT(DISTINCT b.id) AS candidats
FROM surgeries s
JOIN nomenclature a ON a.id = s.nomenclature_id
JOIN nomenclature b ON b.name = s.name AND b.id <> a.id
WHERE s.name <> a.name
  AND COALESCE(s.code, '') = CONCAT(COALESCE(a.code_hospitalisation, ''), COALESCE(a.n, ''))
GROUP BY s.id, s.nomenclature_id, s.code;
```

`candidats > 1` : plusieurs nomenclatures portent ce nom (choix ambigu, pas de correction
automatique possible).

### 3. Répartition par spécialité (taille du problème)

```sql
SELECT a.speciality, COUNT(*) AS interventions
FROM surgeries s
JOIN nomenclature a ON a.id = s.nomenclature_id
WHERE s.name <> a.name
GROUP BY a.speciality
ORDER BY interventions DESC;
```

## Limites de l'interprétation

- Les liens historiques ont été posés par `app:update-surgeries` (par nom, puis par préfixe de
  code). Un `nom_different` peut donc venir de l'historique (nomenclature renommée, ancienne
  saisie libre) et pas du PUT : seule la requête 2 isole la signature du bug.
- `sans_nomenclature` n'est pas causé par le PUT : ce sont des interventions jamais reliées.
- Les interventions `speciality = 'favorites'` viennent de l'ancien front (modification via
  « Mes favoris ») ; elles sont mal classées dans le récapitulatif par spécialité de l'export.

## Correction des données : non faite, décision requise

Si la requête 2 renvoie des lignes, une correction des données pourrait les réaligner sur la
nomenclature probable (relation, code, spécialité). Elle n'est **pas** préparée ici : elle
modifie des carnets de stage officiels, doit être validée séparément (sauvegarde, liste
revue, cas ambigus exclus) et ne fait pas partie du correctif.
