# Plan de mise en production — exports Excel asynchrones

Mise en production du nouveau moteur d'export (worker, suivi, surveillance, gardien), validé sur
la préproduction. **Rien n'est exécuté en production sans l'autorisation explicite du responsable.**
Aucun secret dans ce document. Références : `docs/EXPORTS.md`, `docs/DEPLOIEMENT-VPS.md`.

Notations : 👤 décision ou action humaine, 🤖 action exécutable par l'opérateur, 🔒 point d'arrêt
(on ne continue que si le contrôle est vert).

## Ce qui change

| | Avant | Après |
|---|---|---|
| Export du carnet | synchrone (`GET /api/excel2/{year}`), coupé à 30 s sur les gros carnets | asynchrone (`/api/exports`), worker dédié, progression réelle |
| Routes synchrones | `/api/excel2`, `/api/excel` | conservées (ancien front, onglets ouverts) |
| Conteneurs | db, backend, frontend, legacy-apex-sw | + `worker` (1 CPU / 768 Mo) |
| Base | — | + `export_job`, `export_job_event`, `messenger_messages` (Version20261027090000), `export_monitor_state` (Version20261028090000) : **créations de tables uniquement** |
| Hôte | — | + une ligne de crontab (gardien, chaque minute) |
| Front | v3.1 | v3.2.0 : bouton « Excel » unique, fenêtre de progression, page admin « Surveillance des exports » |

Compatibilité : l'ancien front fonctionne avec le nouveau backend (routes synchrones conservées).
Le nouveau front **exige** le nouveau backend (`/api/exports`) : en cas de retour arrière, front et
backend reviennent ensemble.

## Prérequis (J-1) 🔒

1. 👤 GO écrit pour la mise en production.
2. 👤 Fusion des deux branches validées (backend `feature/export-excel-async` → `master`, front
   `feature/export-excel-progress` → `main`). 🤖 Contrôle : l'arbre fusionné est **identique** à
   celui validé en préproduction (`git diff <sha_validé> <sha_fusion> --stat` vide), sinon nouvelle
   validation en préproduction.
3. 👤 Destinataire des alertes : la production n'a aujourd'hui **ni `ERROR_ALERT_EMAIL` ni
   `EXPORT_ALERT_EMAIL`**. Sans l'une des deux, aucune alerte d'export n'est envoyée (l'échec
   reste visible dans l'administration). Choisir l'adresse (boîte lue par un administrateur).
4. 🤖 Préproduction aux mêmes SHA, tous conteneurs `healthy`, gardien actif dans l'administration.
5. 🤖 Espace disque libre > 10 Go ; sauvegarde nocturne de la nuit précédente présente et vérifiée
   (`restore.sh test <fichier>`).
6. 👤 Fenêtre de faible activité (soir ou tôt le matin) ; durée prévue 30 min, interruption de
   l'API ≈ 1 min (recréation du backend).

## Déroulé (dossier `/opt/stack/apps/medclick-prod`)

### 1. Sauvegardes 🤖🔒

- Sauvegarde chiffrée hors VPS : `./backup.sh /opt/stack/apps/medclick-prod prod` → fichier
  présent localement **et** sur le stockage distant, SHA-256 vérifié.
- Dump local rapide (`mariadb-dump --single-transaction --routines --all-databases | gzip`, chmod 600)
  dans `backups/prod-avant-exports-<date>.sql.gz`, dernière ligne « Dump completed ».
- Copies : `.env`, `deploy.sh`, `docker-compose.yml`, `src/pwa/.commit` et le dossier
  `src/pwa/frontend` → `backups/…-avant-exports`.
- Noter les images en service (`docker ps`) : elles restent sur le VPS (retour arrière sans build).

### 2. Configuration 🤖

- `.env` : ajouter `EXPORT_ALERT_EMAIL=<adresse choisie>` ; rester en 600.
- `BACKEND_TAG`/`FRONTEND_TAG` : rien à faire, `deploy.sh` les enregistre.

### 3. Livraison du front 🤖

`git archive <sha_front> frontend | ssh <vps> "tar -x -C /opt/stack/apps/medclick-prod/src/pwa"`
puis `echo <sha_front> > src/pwa/.commit` (ancien dossier `frontend` déplacé dans `backups/`).

### 4. Déploiement 🤖🔒

1. `deploy.sh` de la version à déployer : `git -C src/backend show <sha_backend>:deploy/vps/deploy.sh > deploy.sh`.
2. `./deploy.sh <sha_backend> <sha_front>` : build, backend recréé, puis **arrêt attendu** sur les
   2 migrations en attente (code 2). L'ancien front reste servi, l'export synchrone fonctionne.
3. 🔒 `doctrine:migrations:status` : exactement `Version20261027090000` et `Version20261028090000`.
4. `doctrine:migrations:migrate --no-interaction` puis `doctrine:schema:validate` vert.
   L'avertissement « previously executed migrations … not registered » est attendu (schéma
   historique géré hors migrations, déjà présent avant ce déploiement).
5. Relancer `./deploy.sh <sha_backend> <sha_front>` : worker démarré, front v3.2.0, `legacy-apex-sw`
   conservé, aucun avertissement final hormis le gardien (étape 5).

### 5. Gardien 🤖

Ajouter dans la crontab de `deploy` (une fois) :

```
* * * * * cd /opt/stack/apps/medclick-prod && flock -n /tmp/medclick-prod-exports-watchdog.lock docker compose exec -T backend php bin/console app:exports:monitor --watchdog >> /home/deploy/logs/medclick-prod-exports-watchdog.log 2>&1
```

## Vérifications après déploiement 🤖🔒

| # | Contrôle | Attendu |
|---|---|---|
| V1 | `docker ps` | backend, worker, frontend, db `healthy` ; images aux SHA déployés ; `legacy-apex-sw` en service |
| V2 | `.env` | `BACKEND_TAG`/`FRONTEND_TAG` = SHA déployés ; `docker compose config` sans erreur |
| V3 | Journal du worker | « Consuming messages from transports "exports, scheduler_exports_monitor" » |
| V4 | `https://www.medclick.be/`, `https://medclick.be/`, `/api/terms-conditions` | 200 ; pied de page « Version 3.2.0 » |
| V5 | Administration → Surveillance des exports (après 2 min) | worker actif, gardien actif |
| V6 | Export réel avec le compte de l'opérateur (👤) : bouton « Excel » | progression réelle, fichier téléchargé, ouvert sans erreur dans Excel, historique « téléchargé » |
| V7 | `GET /api/excel2/{year}` et `/api/excel/{year}` (même compte) | 200, fichier identique au contenu de V6 |
| V8 | Isolation | export d'un autre utilisateur : 404 ; page admin refusée à un non-admin |
| V9 | `docker stats` pendant V6 | worker < 768 Mo, aucun impact visible sur les autres applications du VPS |
| V10 | `error_log` (administration → erreurs) | aucune nouvelle erreur 500 |
| V11 | Alerte (facultatif, 👤 décide) | `docker compose stop worker` 4 min → **un** e-mail « Worker des exports Excel arrêté » ; `docker compose up -d worker` → incident clos |

Un contrôle rouge → diagnostic immédiat ; si non corrigeable en 15 min → retour arrière.

## Surveillance (J0 → J+7)

- Quotidien : page « Surveillance des exports » (échecs, non téléchargés, durées), journal du gardien,
  redémarrages du worker (`docker inspect -f '{{.RestartCount}}' medclick-prod-worker` : redémarrages
  normaux toutes les heures, `--time-limit`), espace disque du volume des exports (expiration 2 h).
- Journaux Traefik : plus aucune réponse 500 à 30 s sur l'export.

## Retour arrière

### R1. Application seule (cas normal, aucune perte de données) 🤖

1. Restaurer `docker-compose.yml` et `deploy.sh` depuis `backups/…-avant-exports`.
2. `.env` : `BACKEND_TAG`/`FRONTEND_TAG` = images notées à l'étape 1 (elles sont sur le VPS).
3. `docker compose up -d --wait backend frontend` ; `docker rm -f medclick-prod-worker`.
4. Retirer la ligne du gardien de la crontab.
5. Restaurer `src/pwa/frontend` et `src/pwa/.commit` (cohérence du prochain `deploy.sh`).
6. Les nouvelles tables restent en base : l'ancien code les ignore. Les suppressions éventuelles
   (`doctrine:migrations:migrate prev`) se décident plus tard, hors urgence.
7. Vérifier V1, V4, V7, V10. Les utilisateurs gardent leur session (mêmes clés JWT).

Durée ≈ 5 min ; les fichiers d'export générés entre-temps deviennent inaccessibles (régénérables).

### R2. Base endommagée (improbable : migrations additives uniquement) 👤🔒

Décision humaine. `restore.sh into` avec le dump de l'étape 1 : **les écritures faites depuis
sont perdues** ; les identifier d'abord (comparaison par table, comme R-A2 de
`docs/RUNBOOK-BASCULE.md`) et les réinjecter.
