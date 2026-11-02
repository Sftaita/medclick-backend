# Plan de mise en production — MedClick 3.6.0 (nomenclature INAMI, étape 4)

Codes INAMI actifs et inactifs, validité historique selon la date réelle de l'intervention
(ADR-210). Recette staging validée le 02/11/2026. **Rien n'est exécuté en production sans
l'autorisation explicite du responsable.** Références : `docs/DEPLOIEMENT-VPS.md` (« Mise à jour
avec migration »), `docs/RECETTE-NOMENCLATURE-ETAPE4.md`.

Notations : 👤 décision ou action humaine, 🤖 action de l'opérateur, 🔒 point d'arrêt (on ne
continue que si le contrôle est vert).

## Ce qui change

| | Avant (3.5.1 affichée, en production) | Après (3.6.0) |
|---|---|---|
| Backend / worker | `f87df67` | commit de fusion de la PR backend (`M`) |
| Frontend | `a624bfc` | commit de fusion de la PR PWA (`N`) |
| Base | — | 2 migrations **additives** : `Version20261103090000` (`nomenclature.active` TRUE par défaut, `inami_repealed_at`, `deactivated_at`, index `(speciality, active)`), `Version20261104090000` (`inami_repeal_source`, `inami_repeal_verified_at`). Aucune ligne modifiée ; les 1 775 codes restent actifs. |
| `deploy.sh` du dossier | `3bed3dd4` (sans verrou de schéma) | celui de `M` : verrou de schéma, mode `--migrate`, conteneurs temporaires hors Traefik |
| Journaux | — | `var/log/nomenclature-prod-AAAA-MM.log` (changements de statut des codes) |
| Compose de production | — | **inchangé** (le service `recette-acces` n'existe qu'en préproduction) |

Compatibilité : l'ancienne version (`f87df67` / `a624bfc`) fonctionne sur le schéma enrichi
(vérifié sur staging) ; le nouveau backend exige les colonnes (verrou de schéma).

## Interruption de service

- **Migrations : sans interruption.** Exécutées par le nouveau code dans un conteneur temporaire,
  pendant que l'ancienne version reste en service (sur staging : 150 requêtes sur 150 en 200).
- **Remplacement des services : courte indisponibilité possible.** Le backend (un seul conteneur)
  est recréé, puis le worker et le frontend : quelques secondes pendant lesquelles des requêtes
  peuvent échouer (sur staging : 1 requête sur 92 sans réponse). Un export en cours sur le worker se
  termine avant l'arrêt (période de grâce de 120 s). 👤 Choisir une heure creuse.

## 0. Prérequis 🔒

1. 👤 PR fusionnées (commits de fusion `M` backend, `N` PWA) ; CI GitHub verte sur `M`.
2. 🤖 Contenu fusionné = contenu recetté : `git diff --quiet <dernier commit de la branche backend> M`
   et `git diff --quiet <dernier commit de la branche PWA> N -- frontend` (aucune autre PR fusionnée
   entre-temps) ; sinon refaire la recette staging. Recette navigateur faite sur `aac51b3` / `bf73d28` ;
   les deux derniers commits PWA (textes des dialogues de statut, numéro de version 3.6.0) sont
   couverts par les tests automatisés — 👤 contrôle visuel staging facultatif avant la production.
3. 🤖 État de la production : backend, worker, frontend sains ; `./deploy.sh --verify f87df67485757a2c9714975e2d4c889c334b4426 a624bfcd44bea335a0cdcd0824f67dfb3370f0cb` (code 0).
4. 🤖 Aucun export `pending` / `running`, file `messenger_messages` vide (sinon attendre).
5. 🤖 Instantané des autres applications du VPS (noms, identifiants, images, dates de création).

## 1. Sauvegardes et empreintes 🔒

```bash
cd /opt/stack/apps/medclick-prod
TS=$(date -u +%Y%m%d-%H%M%S)
docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump -u"$MARIADB_USER" --single-transaction --no-tablespaces --routines --triggers "$MARIADB_DATABASE"' | gzip > backups/prod-avant-3.6.0-$TS.sql.gz
gzip -t backups/prod-avant-3.6.0-$TS.sql.gz && zcat backups/prod-avant-3.6.0-$TS.sql.gz | tail -n 1   # « Dump completed »
sha256sum backups/prod-avant-3.6.0-$TS.sql.gz > backups/prod-avant-3.6.0-$TS.sql.gz.sha256 && chmod 600 backups/prod-avant-3.6.0-$TS.sql.gz
mkdir -m 700 backups/config-avant-3.6.0-$TS && cp -p .env docker-compose.yml deploy.sh backups/config-avant-3.6.0-$TS/
cp -p src/pwa/.commit backups/pwa-commit-avant-3.6.0-$TS
docker images --format '{{.Repository}}:{{.Tag}} {{.ID}}' | grep medclick-prod > backups/images-avant-3.6.0-$TS.txt
validation/nomenclature-etape3-fingerprint.sh 3.6.0-1-avant     # interventions, nomenclature, favoris
```

Contrôles : sauvegarde complète (nombre de tables = base), sha256 enregistré, empreintes
relevées (référence du 01/11/2026 : 1 775 codes, empreinte 3860294714456).

## 2. Livraison des sources et du script

```bash
# poste : archive du frontend au commit N (le frontend en service n'est pas touché)
git -C <pwa> archive N frontend | ssh surgicalhub-prod "cd /opt/stack/apps/medclick-prod && mv src/pwa src/pwa.a624bfc && mkdir src/pwa && tar -x -C src/pwa && echo N > src/pwa/.commit"
# VPS : deploy.sh de M (l'ancien est déjà dans backups/config-avant-3.6.0-$TS/)
git -C src/backend fetch -q origin && git -C src/backend show M:deploy/vps/deploy.sh > deploy.sh.nouveau
bash -n deploy.sh.nouveau && chmod 755 deploy.sh.nouveau && mv deploy.sh.nouveau deploy.sh
```

## 3. Verrou de schéma 🔒

```bash
./deploy.sh M N; echo "code $?"     # attendu : 2, « ARRÊT (aucun service remplacé) : 2 migration(s) en attente »
```

Contrôles : identifiants des conteneurs backend, worker, frontend et `legacy-apex-sw` inchangés ;
`BACKEND_TAG` / `FRONTEND_TAG` de `.env` inchangés ; aucune colonne `active` dans `nomenclature`.

## 4. Migrations, ancienne version en service 🔒

```bash
./deploy.sh --migrate M N; echo "code $?"   # sauvegarde automatique vérifiée, puis 2 migrations
```

Contrôles :
- code 0 ; `backups/avant-migration-<M>-<date>.sql.gz` créé et vérifié ;
- services en service inchangés (identifiants, images `f87df67` / `a624bfc`), sains ;
- `SELECT COUNT(*), SUM(active), SUM(inami_repealed_at IS NOT NULL), SUM(inami_repeal_verified_at IS NOT NULL), SUM(deactivated_at IS NOT NULL) FROM nomenclature` → 1775, 1775, 0, 0, 0 ;
- empreintes `3.6.0-2-apres-migration` identiques à `3.6.0-1-avant` (ligne par ligne) ;
- ancienne version sur le schéma enrichi : journaux backend / worker / frontend sans erreur ni
  5xx, `error_log` sans nouvelle entrée, requêtes publiques et pages principales en 200, exports
  réels traités normalement s'il y en a. Aucun test d'écriture sur des comptes réels.

## 5. Mise en service (courte indisponibilité possible)

```bash
./deploy.sh M N; echo "code $?"          # attendu : 0, « verrou de schéma : aucune migration en attente »
./deploy.sh --verify M N; echo "code $?" # attendu : 0 (backend et worker même image, code = M, schéma à jour)
```

## 6. Contrôles après mise en service 🔒

- Empreintes `3.6.0-3-apres-deploiement` identiques à l'avant (interventions, nomenclature, favoris).
- Nomenclature : 1 775 codes actifs, aucune date d'abrogation, aucune vérification.
- Pied de page de la PWA : « Version 3.6.0 » ; pages principales, manifeste, service worker et
  ressources en 200 ; API publique en 200, API protégée en 401 sans jeton.
- Export Excel en lecture seule (script de contrôle de l'étape 3 : codes du carnet = codes
  enregistrés, codes historiques présents, fichier temporaire supprimé).
- Journaux backend / worker / frontend et `error_log` : aucune erreur ni 5xx depuis le déploiement ;
  gardien des exports en place.
- Autres applications du VPS : noms, identifiants, images, dates de création inchangés.
- 🤖 Note de retour arrière : `validation/3.6.0-RETOUR-ARRIERE.txt` (600) avec les SHA et sauvegardes.

## Retour arrière

| Moment | Action |
|---|---|
| Après §3 (verrou) | Rien à défaire en service : `mv src/pwa src/pwa.N && mv src/pwa.a624bfc src/pwa` ; ancien `deploy.sh` restauré depuis `backups/config-avant-3.6.0-$TS/` si souhaité. |
| Après §4 (migrations) | L'ancienne version fonctionne sur le schéma enrichi : laisser les colonnes. Retrait seulement sur décision 👤 : `migrate` vers `DoctrineMigrations\Version20261102090000` dans un conteneur temporaire du code `M` (`docker compose run --rm --no-deps -T -l traefik.enable=false backend …` avec `BACKEND_TAG` de `M`), après une sauvegarde. |
| Après §5 (mise en service) | `mv src/pwa src/pwa.N && mv src/pwa.a624bfc src/pwa` puis `./deploy.sh f87df67485757a2c9714975e2d4c889c334b4426 a624bfcd44bea335a0cdcd0824f67dfb3370f0cb` (le verrou laisse passer : l'ancien code n'a aucune migration en attente). Colonnes conservées, aucune restauration de base. |
| Dernier recours | Restauration de `backups/prod-avant-3.6.0-$TS.sql.gz` : 👤 décision explicite, **perte des écritures postérieures à la sauvegarde**. |

Aucune donnée n'est modifiée par la livraison : aucun code désactivé, aucune date d'abrogation
saisie. La désactivation de codes réels et la saisie des dates officielles vérifiées sont des
opérations ultérieures, sur décision, après validation de la liste officielle.
