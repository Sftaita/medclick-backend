# Recette staging — Nomenclature INAMI, étape 3 (codes historiques préservés)

Branches : backend `fix/nomenclature-historique`, PWA `fix/edition-intervention-nomenclature`
(toutes deux créées depuis la production 3.5.1 : backend `f5d13db`, PWA `d8250df`).
Aucune migration, aucune commande de données : seul le comportement de la modification d'une
intervention change. **Préproduction uniquement** (`/opt/stack/apps/medclick-staging`) ; rien
sur `medclick-prod` ni sur les autres applications du VPS.

## Règle recettée

Une intervention enregistrée garde son code INAMI, son intitulé, sa spécialité et sa référence
`nomenclature_id` tant que l'utilisateur ne choisit pas un **autre** acte. Renvoyer l'acte déjà
lié (ou n'en envoyer aucun) ne recopie plus le code actuel de la nomenclature.
Routes : `PUT`/`PATCH /api/surgeries/{id}` (fronts) et `PUT /api/surgeries/update/{id}` (ancienne).

## 0. Empreinte des interventions avant déploiement (lecture seule, base staging)

```sql
SET SESSION TRANSACTION READ ONLY;
SELECT COUNT(*) AS n,
       SUM(CRC32(CONCAT_WS('|', id, IFNULL(nomenclature_id,''), IFNULL(code,''), name, speciality,
                           date, position, IFNULL(first_hand,''), IFNULL(second_hand,''), IFNULL(year_id,'')))) AS empreinte
FROM surgeries;
```

Noter `n` et `empreinte`. Conserver aussi l'empreinte **ligne par ligne** (fichier sur le VPS,
hors dépôt, supprimé après la recette) :

```bash
cd /opt/stack/apps/medclick-staging
docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -N -u"$MARIADB_USER" "$MARIADB_DATABASE" -e "SELECT id, CRC32(CONCAT_WS(\"|\", IFNULL(nomenclature_id,\"\"), IFNULL(code,\"\"), name, speciality, date, position, IFNULL(first_hand,\"\"), IFNULL(second_hand,\"\"), IFNULL(year_id,\"\"))) FROM surgeries ORDER BY id"' > ~/recette-etape3-avant.tsv
```

## 1. Déploiement staging

Comme `docs/DEPLOIEMENT-VPS.md` (« Mise à jour »), dans `/opt/stack/apps/medclick-staging` :
livrer l'archive `frontend` de la PWA au SHA de la branche (`src/pwa` + `.commit`), puis
`./deploy.sh <sha_backend> <sha_front>`. `deploy.sh` doit se terminer **sans migration en attente**.

## 2. Contrôle « aucune donnée modifiée par le déploiement »

Relancer la requête du §0 : `n` et `empreinte` **identiques**.

## 3. Scénarios fonctionnels (compte de recette staging)

Choisir, avec le compte de recette, une intervention dont le code diffère du code actuel de sa
nomenclature (cas des codes renumérotés) :

```sql
SELECT s.id, s.code, CONCAT(x.code_hospitalisation, x.n) AS code_actuel
FROM surgeries s JOIN nomenclature x ON x.id = s.nomenclature_id
JOIN years y ON y.id = s.year_id
WHERE y.user_id = <id du compte de recette> AND s.code <> CONCAT(x.code_hospitalisation, x.n)
LIMIT 5;
```

À défaut, en prendre une ordinaire : les scénarios restent valables (code inchangé attendu).

| # | Action dans la PWA (modification de l'intervention) | Attendu en base |
|---|---|---|
| 1 | Changer seulement la date, enregistrer | `code`, `name`, `speciality`, `nomenclature_id` inchangés ; `date` modifiée |
| 2 | Changer seulement le rôle (position) | idem ; `position` modifiée |
| 3 | Changer seulement le superviseur | idem ; `first_hand`/`second_hand` modifiés |
| 4 | Changer la spécialité, fermer la liste sans choisir, enregistrer | identité inchangée (spécialité comprise) |
| 5 | Rouvrir la liste et choisir **le même** acte, enregistrer | identité inchangée (ancien code conservé) |
| 6 | Choisir **un autre** acte, enregistrer | `code` = code actuel du nouvel acte ; `name`, `speciality`, `nomenclature_id` du nouvel acte |
| 7 | Exporter le carnet (Excel) de l'année | les interventions 1 à 5 apparaissent avec leur code historique |
| 8 | Sur une intervention enregistrée avec `speciality = 'favorites'` : changer la date, enregistrer | `speciality` reste `favorites` ; code, nom, `nomenclature_id` inchangés |

Vérification après chaque scénario :

```sql
SELECT id, code, name, speciality, nomenclature_id, date, position, first_hand, second_hand
FROM surgeries WHERE id = <id>;
```

## 4. Contrôle final

Refaire l'empreinte ligne par ligne dans `~/recette-etape3-apres.tsv` (même commande), puis
`diff ~/recette-etape3-avant.tsv ~/recette-etape3-apres.tsv` : seules les interventions
modifiées pendant les scénarios 1 à 6 doivent apparaître. Supprimer ensuite les deux fichiers.

## Retour arrière

`./deploy.sh <sha_backend_précédent> <sha_front_précédent>` ; aucune donnée à restaurer
(pas de migration).
