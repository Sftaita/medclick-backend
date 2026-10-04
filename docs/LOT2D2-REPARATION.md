# LOT 2D.2 : réparation des interventions incohérentes (procédure)

Contexte et requêtes de détection : `docs/LOT2D1-INCOHERENCES.md`. Le correctif applicatif
(`SurgeryNomenclatureProcessor`, déployé le 04/10/2026, `master` = `4bb701b`) empêche les
nouveaux cas mais ne répare pas l'historique.

## Périmètre

Commande `app:repair-surgery-nomenclature`, algorithme dans `src/Repair/SurgeryNomenclatureRepairPlanner.php`.
Seule la catégorie **A1** est modifiée.

| Catégorie | Définition | Action |
|---|---|---|
| A1 | code = code de la nomenclature liée (SOURCE), nom ≠ nom de SOURCE, **une seule** autre nomenclature (TARGET) porte exactement ce nom, TARGET complète, spécialité = celle de TARGET ou `favorites`, `createdAt` renseigné (créée par `addNewSurgery`) | relation, code, spécialité ← TARGET (le nom est déjà celui de TARGET) |
| A2 | comme A1 mais sans `createdAt` (antérieure à décembre 2024, reliée par `app:update-surgeries`) | exclue |
| B | cible unique mais spécialité ni TARGET ni `favorites` | exclue |
| C | plusieurs nomenclatures portent ce nom | exclue |
| sans cible | aucune nomenclature de ce nom **exact** (comparaison binaire : casse et accents comptent) | exclue |
| cible incomplète | TARGET sans nom, spécialité ou code d'hospitalisation | exclue |
| D | autres incohérences (sans nomenclature, codes historiques, `favorites` hors signature…) | hors périmètre |

Pas d'approximation : en cas de doute, la ligne est ignorée.

## Garanties

- **Dry-run par défaut** : uniquement des `SELECT`, aucun fichier écrit.
- `--apply` exige `--snapshot-dir` **hors du projet** (le projet est servi sous `public_html`).
- Le snapshot complet (avant/après de chaque ligne) est écrit, synchronisé sur disque et passé en
  `0600` **avant** la première modification.
- Mises à jour par lots transactionnels (`--batch-size`, 200 par défaut), **conditionnelles** :
  la ligne n'est modifiée que si elle a encore exactement ses valeurs « avant » ; sinon elle est
  journalisée « ignorée (modifiée entre-temps) ».
- Journal ligne à ligne (`*.applied.csv` : run, id, date, résultat).
- Idempotente : une ligne corrigée n'est plus candidate ; une seconde exécution ne modifie rien.
- Fichiers sans donnée personnelle : identifiants techniques, codes, spécialités, version du script.

## Procédure (après autorisation explicite)

```bash
# 0. Déployer la branche de la commande selon DEPLOIEMENT.md (sauvegarde de la base comprise).
cd ~/domains/easymed.fun/public_html/medclick/backend
S=~/backups/repair-2d2-$(date +%Y%m%d-%H%M); mkdir -p $S && chmod 700 $S

# 1. Dry-run : vérifier les compteurs (A1 attendu, exclusions)
php bin/console app:repair-surgery-nomenclature --env=prod

# 2. Application
php bin/console app:repair-surgery-nomenclature --env=prod --apply --snapshot-dir=$S

# 3. Contrôles : second dry-run (A1 = 0), requête 2 de LOT2D1-INCOHERENCES.md (signature = A2 + B + C)
php bin/console app:repair-surgery-nomenclature --env=prod

# 4. Copier $S hors du serveur (stockage sécurisé), le conserver avec la sauvegarde de la base.
```

## Retour arrière

1. Ciblé : `--rollback=$S/repair-surgery-nomenclature-<run>.csv` (dry-run), puis avec `--apply`.
   Seules les lignes qui ont encore les valeurs « après » sont restaurées.
2. Global (dernier recours) : restauration de la sauvegarde `mysqldump` prise juste avant.

## Carnets Excel déjà exportés

Les fichiers déjà téléchargés ne sont pas modifiables : ceux qui contiennent une intervention A1
affichent le code de A à côté du nom de B, et la comptent sous A dans le récapitulatif. Après
correction, un export régénéré est cohérent (test `testExcelExportIsIncoherentBeforeAndCoherentAfterRepair`).
Informer ou non les utilisateurs concernés : décision séparée.

## Portée « favorites » (LOT 2D.3)

`--scope=favorites` traite les interventions dont `speciality = "favorites"` : l'ancien PUT recopiait
cette valeur (choix « Mes favoris »). Effet : l'intervention figure dans le carnet mais **pas** dans le
récapitulatif `excel2` (classement par spécialité). Depuis le correctif du processor (2D.3), le PUT
remplace « favorites » par la spécialité de la nomenclature liée.

| Catégorie | Condition | Action |
|---|---|---|
| F | nomenclature liée complète, code et nom actuels identiques aux siens | spécialité ← celle de la nomenclature, rien d'autre |
| F exclues | sans nomenclature, nomenclature incomplète, code différent, nom différent | aucune |

Le snapshot montre relation et code identiques avant/après ; seule la spécialité diffère.
Même procédure que ci-dessus en ajoutant `--scope=favorites` (dry-run, `--apply --snapshot-dir`,
`--rollback`). Snapshot et journal sont créés en `0600`.
