# Recette staging — Nomenclature INAMI, étape 4 (codes actifs et inactifs)

Branches : backend `feature/nomenclature-etape4-actif` (depuis `master` `f87df67`, production),
PWA `feature/nomenclature-etape4-actif` (depuis `main` `a624bfc`, production). Décision : ADR-210
(`docs/DECISIONS.md`). **Préproduction uniquement** (`/opt/stack/apps/medclick-staging`) ; rien sur
`medclick-prod` ni sur les autres applications du VPS. À lancer seulement après autorisation.

## Ce qui change

- Migration additive `Version20261103090000` : `nomenclature.active` (TRUE par défaut),
  `inami_repealed_at` (date officielle), `deactivated_at` (date technique), index
  `(speciality, active)`. Aucune ligne supprimée, renumérotée ou modifiée.
- Liste de choix d'un acte (`/api/nomenclature/{speciality}`) : codes actifs seulement.
- Code inactif refusé pour une nouvelle intervention, un nouveau favori ou un changement d'acte
  (routes nouvelles et anciennes) ; accepté s'il est déjà lié et inchangé.
- Favoris : `active` = statut de la nomenclature liée ; PWA : grisés, « Code INAMI inactif »,
  non sélectionnables pour une intervention, toujours modifiables et supprimables.
- Administration (`/admin/nomenclature`) : toutes spécialités, statut, deux dates, filtre « Code
  INAMI », recherche code / intitulé / spécialité, bouton Désactiver / Réactiver avec confirmation
  (nombre d'interventions et de favoris liés affiché).

## Test de la migration déjà réalisé (base isolée, 01/11/2026)

Conteneur MariaDB 11.8 jetable sur le VPS (réseau interne dédié, aucun port, supprimé ensuite),
restauré depuis `backups/avant-recette-finale-20261101-170203.sql.gz` (staging), migration exécutée
par l'image `medclick-backend:f87df67` avec la migration et l'entité de la branche :

| Contrôle | Résultat |
|---|---|
| Nomenclature restaurée = production (01/11) | 1 775 lignes, empreinte 3860294714456 : identique |
| Migration | OK (6 s, démarrage du conteneur compris), 1 seule migration en attente |
| Après migration | 1 775 codes, 1 775 actifs, aucune date ; ids 5036 à 6969 inchangés |
| Empreintes interventions / nomenclature / favoris (globales et ligne par ligne) | identiques |
| Export de l'année 109 (804 interventions, 440 codes historiques), horloge figée | identique avant migration, après migration et avec **tous** les codes désactivés |
| Interventions et favoris après désactivation de tous les codes | inchangés |
| `down` puis ré-application | colonnes retirées, données identiques à l'origine ; ré-application OK |
| `doctrine:schema:update --dump-sql` | seul écart : index `wdbi_name_191_031fbf8277a9` sur `nomenclature.name`, **préexistant** (créé hors migrations), non lié à l'étape 4 |

## 0. Sauvegarde et empreintes (staging)

```bash
cd /opt/stack/apps/medclick-staging
TS=$(date -u +%Y%m%d-%H%M%S)
docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump -u"$MARIADB_USER" --single-transaction --no-tablespaces --routines --triggers "$MARIADB_DATABASE"' | gzip > backups/avant-etape4-$TS.sql.gz
gzip -t backups/avant-etape4-$TS.sql.gz && sha256sum backups/avant-etape4-$TS.sql.gz > backups/avant-etape4-$TS.sql.gz.sha256
cp -p src/pwa/.commit backups/pwa-commit-avant-etape4-$TS
```

Empreintes (lecture seule) : mêmes formules que l'étape 3 (interventions, nomenclature, favoris ;
globales et ligne par ligne, fichiers `validation/` en 600). Les nouvelles colonnes n'entrent pas
dans la formule de la nomenclature : l'empreinte doit rester identique après la migration.

## 1. Migration puis déploiement staging, sans interruption

Procédure de `docs/DEPLOIEMENT-VPS.md`, « Mise à jour avec migration ». Le `deploy.sh` du dossier
de l'environnement est d'abord remplacé par celui de la branche (copie précédente dans `backups/`).

```bash
# poste : archive du frontend au SHA de la branche PWA (le frontend en service n'est pas touché)
git -C <pwa> archive <sha_front> frontend | ssh surgicalhub-prod "cd /opt/stack/apps/medclick-staging && mv src/pwa src/pwa.a624bfc && mkdir src/pwa && tar -x -C src/pwa && echo <sha_front> > src/pwa/.commit"
# VPS
cd /opt/stack/apps/medclick-staging
cp -p deploy.sh backups/deploy.sh-avant-etape4-$TS && git -C src/backend fetch -q origin && git -C src/backend show <sha_backend>:deploy/vps/deploy.sh > deploy.sh
./deploy.sh <sha_backend> <sha_front>; echo "code $?"          # attendu : 2, AUCUN service remplacé
./deploy.sh --migrate <sha_backend> <sha_front>; echo "code $?"  # sauvegarde + migration, services inchangés
#   ancienne version : conteneurs identiques et sains, requêtes (lecture, écriture de recette, export), journaux
./deploy.sh <sha_backend> <sha_front>; echo "code $?"          # attendu : 0, « Vérification finale : OK »
./deploy.sh --verify <sha_backend> <sha_front>; echo "code $?" # attendu : 0
```

Contrôles du verrou : après le premier `deploy.sh` (code 2), identifiants des conteneurs backend,
worker et frontend inchangés, étiquettes de `.env` inchangées, aucune colonne ajoutée.

## 2. Contrôles après déploiement (lecture seule)

| # | Contrôle | Attendu |
|---|---|---|
| 1 | `SELECT COUNT(*), SUM(active), SUM(inami_repealed_at IS NOT NULL), SUM(deactivated_at IS NOT NULL) FROM nomenclature` | 1775, 1775, 0, 0 |
| 2 | Empreintes après déploiement vs §0 | identiques (3 tables) |
| 3 | `/admin/nomenclature`, « Toutes les spécialités » | 1 775 actif(s) · 0 inactif(s) ; colonnes statut et dates |
| 4 | PWA : création d'une intervention, favoris, liste des interventions | inchangées (aucun code inactif) |
| 5 | Journaux backend / worker / frontend, `error_log` | aucune erreur ni 5xx |

## 3. Scénarios fonctionnels (compte de recette staging)

Nécessitent une désactivation **sur staging uniquement**. Recommandé : un code de recette dédié,
créé dans l'administration (« + Ajouter », orthopédie, intitulé « RECETTE ÉTAPE 4 — ne pas
utiliser »), pour ne désactiver aucun code existant. Il reste en base (aucune suppression) ;
le laisser inactif à la fin.

| # | Scénario | Attendu |
|---|---|---|
| 1 | Compte de recette : intervention avec le code de recette (date passée) + favori « R4 » sur ce code | créés |
| 2 | Export Excel de l'année de recette | référence « avant » |
| 3 | Admin : Désactiver le code de recette, date officielle saisie | dialogue : « 1 intervention(s) et 1 favori(s) … restent enregistrés » ; Annuler n'envoie rien ; Confirmer → « Inactif », dates affichées |
| 4 | PWA : nouvelle intervention, orthopédie | code de recette absent de la liste |
| 5 | PWA : « Mes favoris » dans le formulaire | « R4 » visible, grisé, « Code INAMI inactif », non sélectionnable |
| 6 | PWA : page Favoris | « R4 » grisé, mention affichée, Modifier / Supprimer disponibles ; formulaire : avertissement |
| 7 | API : `POST /api/surgeries/addNewSurgery` avec l'id du code de recette | 400, `reason: nomenclature_inactive` ; rien créé |
| 8 | PWA : modifier l'intervention du scénario 1 (date seule, puis rôle) | enregistré ; code, intitulé, spécialité, `nomenclature_id` inchangés |
| 9 | Export Excel de l'année de recette | identique au scénario 2 (hors date modifiée) ; aucune ligne perdue |
| 10 | Statistiques du compte de recette (admin, recalcul) | identiques avant / après désactivation |
| 11 | Admin : Réactiver puis Désactiver de nouveau | réactivé : proposé et favori utilisable ; date technique effacée puis refixée |
| 12 | `type` / `sub_type` de toute la nomenclature | empreinte nomenclature identique, hors code de recette ajouté |
| 13 | Empreintes interventions et favoris vs §0 | seules les lignes de recette (créées ou modifiées) diffèrent |
| 14 | Journaux, `error_log` | aucune erreur ni 5xx ; trace `Nomenclature INAMI : statut modifié` pour chaque changement dans `var/log/nomenclature-prod-AAAA-MM.log` |

## Retour arrière staging

```bash
mv src/pwa src/pwa.<sha_front> && mv src/pwa.a624bfc src/pwa
./deploy.sh f87df67485757a2c9714975e2d4c889c334b4426 a624bfcd44bea335a0cdcd0824f67dfb3370f0cb
```

Les trois colonnes sont ignorées par la version précédente : la migration peut rester en place.
Pour la retirer aussi (après une sauvegarde) : `doctrine:migrations:migrate prev` **avant** de
redéployer l'ancienne version (testé sur la base isolée). Sauvegarde du §0 en dernier recours.

## Validité historique (migration `Version20261104090000`, même procédure sans interruption)

Sur le code de recette 6970 (inactif, abrogation au 01/10/2026 non vérifiée au départ) :

| # | Scénario | Attendu |
|---|---|---|
| H1 | Date non vérifiée : intervention du 15/09/2026 avec le code (API et PWA) | refusée ; code absent de la liste du 15/09/2026 |
| H2 | Admin : « Abrogation », source saisie, case « vérifiée » cochée | « vérifiée » affiché ; trace dans `nomenclature-prod-AAAA-MM.log` |
| H3 | PWA : nouvelle intervention datée du 15/09/2026, orthopédie | code proposé, mention « Ancien code INAMI : uniquement pour une intervention avant le 01/10/2026 » ; enregistrement accepté |
| H4 | Même saisie datée du 01/10/2026 et du 15/10/2026 | code absent de la liste ; API : 400 (trois routes de création) |
| H5 | Date passée du 15/09 au 05/10 après choix de l'ancien code | acte retiré, message sous « Intervention », rien envoyé |
| H6 | Intervention historique (acte inchangé) déplacée au 05/10/2026 | refusée, message sous « Date » ; code et date inchangés en base |
| H7 | Même intervention déplacée au 20/09/2026 | acceptée, code historique conservé |
| H8 | « Mes favoris » : R4 avant le 01/10/2026 / après | sélectionnable et signalé / grisé |
| H9 | Changement d'acte vers le code de recette, date antérieure / postérieure | accepté / refusé (422 sur `name`) |
| H10 | Source modifiée sans nouvelle vérification | « non vérifiée » ; H3 de nouveau refusé |
| H11 | Empreintes, export, journaux | 1 775 codes existants actifs et inchangés ; seules les lignes de recette diffèrent ; aucune erreur |

### Abrogation vérifiée prioritaire sur `active` (à jouer après autorisation)

Sur un code de recette ACTIF dont l'abrogation vérifiée est fixée (ex. 01/10/2026) et sur le code
6970 (inactif) : veille (30/09) acceptée, jour exact (01/10) et lendemain (02/10) refusés, en
création (PWA et trois routes API), en changement d'acte et en déplacement de date ; réactivation
sans effet sur ces refus ; écrans : favori « Code INAMI abrogé » grisé, administration « Actif » +
« Code INAMI abrogé ».

## Décisions métier encore nécessaires

1. **Saisie tardive** d'un acte réalisé avant l'abrogation (ex. intervention de juin saisie en
   décembre, code abrogé en septembre) : aujourd'hui **refusée**, le code inactif n'étant plus
   proposé. Options :
   a. refus maintenu : l'assistant choisit le code de remplacement ;
   b. code inactif accepté si la date de l'intervention précède `inami_repealed_at` (date
      officielle alors obligatoire ; liste à filtrer selon la date choisie) ;
   c. délai de grâce après la désactivation.
2. Date officielle d'abrogation **obligatoire** ou non pour désactiver (prérequis de 1b).
3. Réactivation : conserver ou effacer la date officielle (aujourd'hui conservée, sauf si
   l'administrateur la vide).
4. Code de **remplacement** : faut-il relier un code abrogé à son successeur (suggestion dans la
   PWA, aide à la mise à jour des favoris) ? Aucune redirection automatique n'est faite.
5. Une intervention historique dont l'acte est changé ne peut plus revenir à son ancien code
   inactif : à confirmer.
6. Journal des désactivations : trace dans les journaux applicatifs seulement ; table d'historique
   (qui, quand, pourquoi) souhaitée ?
7. Liste officielle des codes à désactiver : validation et méthode d'import (hors étape 4).
