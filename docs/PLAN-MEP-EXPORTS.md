# Plan de mise en production — MedClick 3.2.0 (exports Excel)

Mise en production de MedClick 3.2.0 : export Excel asynchrone (worker, suivi, surveillance,
gardien), validé sur la préproduction. **Rien n'est exécuté en production sans l'autorisation
explicite du responsable.** **Exécuté le 28/10/2026 : voir « Journal d'exécution » en fin de
document.** Aucun secret ni adresse personnelle dans ce document. Références :
`docs/EXPORTS.md`, `docs/DEPLOIEMENT-VPS.md`.

Notations : 👤 décision ou action humaine, 🤖 action exécutable par l'opérateur, 🔒 point d'arrêt
(on ne continue que si le contrôle est vert).

## Ce qui change

| | Avant | Après |
|---|---|---|
| Export du carnet | synchrone (`GET /api/excel2/{year}`), coupé à 30 s sur les gros carnets | API canonique `/api/exports` (asynchrone, worker dédié, progression réelle) |
| `/api/excel2`, `/api/excel` | export synchrone | alias de compatibilité (même générateur, même contrat) pour le front CRA et les onglets ouverts |
| Conteneurs | db, backend, frontend, legacy-apex-sw | + `worker` (1 CPU / 768 Mo) |
| Base | — | migrations **additives** : `Version20261027090000` (tables `export_job`, `export_job_event`, `messenger_messages`), `Version20261028090000` (table `export_monitor_state`), `Version20261029090000` (colonnes `watchdog_alert_*` de `export_monitor_state`). Aucune table existante modifiée. |
| Hôte | — | + une ligne de crontab (gardien, chaque minute) |
| Alertes | aucun destinataire configuré | `ERROR_ALERT_EMAIL` (erreurs 500, erreurs des fronts) et `EXPORT_ALERT_EMAIL` (exports, worker, gardien) |
| Front | v3.1 | v3.2.0 : un bouton « Excel » par année, fenêtre de progression, page admin « Surveillance des exports » |
| Dépendances | symfony/expression-language 8.1.6, dompurify 3.4.16 | 8.1.9, 3.4.17 (correctifs de sécurité) |

Compatibilité : l'ancien front fonctionne avec le nouveau backend (alias conservés). Le nouveau
front **exige** le nouveau backend (`/api/exports`) : en cas de retour arrière, front et backend
reviennent ensemble.

## Prérequis (J-1) 🔒

1. 👤 GO écrit pour la mise en production.
2. 👤 Fusion des deux branches validées (backend `feature/export-excel-async` → `master`, front
   `feature/export-excel-progress` → `main`), **sans** squash ni rebase. 🤖 Contrôle : l'arbre
   fusionné est identique à celui validé en préproduction (`git diff <sha_validé> <sha_fusion>
   --stat` vide ; les deux branches contiennent déjà `master`/`main`, la fusion ne change donc pas
   le contenu), sinon nouvelle validation en préproduction.
3. 👤 Adresse des alertes techniques communiquée (hors dépôt).
4. 🤖 Préproduction aux mêmes contenus, tous conteneurs `healthy`, worker et gardien actifs dans
   l'administration.
5. 🤖 Espace disque libre > 10 Go ; sauvegarde nocturne de la nuit précédente présente et vérifiée
   (`restore.sh test <fichier>`).
6. 👤 Fenêtre de faible activité (soir ou tôt le matin) ; durée prévue 30 min, interruption de
   l'API ≈ 1 min (recréation du backend).

## Déroulé (dossier `/opt/stack/apps/medclick-prod`)

### 1. Sauvegardes 🤖🔒

- Sauvegarde chiffrée hors VPS : `./backup.sh /opt/stack/apps/medclick-prod prod` → fichier
  présent localement **et** sur le stockage distant, SHA-256 vérifié.
- Dump local rapide (`mariadb-dump --single-transaction --routines --all-databases | gzip`, chmod 600)
  dans `backups/prod-avant-3.2.0-<date>.sql.gz`, dernière ligne « Dump completed ».
- Copies (chmod 600) : `.env`, `deploy.sh`, `docker-compose.yml`, `src/pwa/.commit`, le dossier
  `src/pwa/frontend` et la crontab (`crontab -l`) → `backups/…-avant-3.2.0`.
- Noter les images en service (`docker ps`) : elles restent sur le VPS (retour arrière sans build).

### 2. Configuration 🤖

- `.env` : `ERROR_ALERT_EMAIL=<adresse>` et `EXPORT_ALERT_EMAIL=<adresse>` ; rester en 600.
  `BACKEND_TAG`/`FRONTEND_TAG` : rien à faire, `deploy.sh` les enregistre.
- Le Compose de la nouvelle version (copié par `deploy.sh`) transmet ces deux variables au backend
  et au worker.

### 3. Livraison du front 🤖

`git archive <sha_front> frontend | ssh <vps> "tar -x -C /opt/stack/apps/medclick-prod/src/pwa"`
puis `echo <sha_front> > src/pwa/.commit` (ancien dossier `frontend` déplacé dans `backups/`).

### 4. Déploiement 🤖🔒

1. `deploy.sh` de la version à déployer : `git -C src/backend show <sha_backend>:deploy/vps/deploy.sh > deploy.sh`.
2. `./deploy.sh <sha_backend> <sha_front>` : build, backend recréé, puis **arrêt attendu** sur les
   migrations en attente (code 2). L'ancien front reste servi, l'export synchrone fonctionne.
3. 🔒 `doctrine:migrations:status` : exactement `Version20261027090000`, `Version20261028090000` et
   `Version20261029090000`.
4. `doctrine:migrations:migrate --no-interaction` puis `doctrine:schema:validate` vert.
   L'avertissement « previously executed migrations … not registered » est attendu (schéma
   historique géré hors migrations, déjà présent avant ce déploiement).
5. Relancer `./deploy.sh <sha_backend> <sha_front>` : worker démarré, front v3.2.0, `legacy-apex-sw`
   conservé ; seul avertissement final attendu : gardien absent de la crontab (étape 5).

### 5. Gardien 🤖🔒

Ajout à la crontab de `deploy` (ajout seul, les autres lignes inchangées ; `diff` avec la copie de
l'étape 1 = exactement ces lignes) :

```
# MedClick production : gardien des exports Excel (docs/DEPLOIEMENT-VPS.md)
CRON_TZ=UTC
* * * * * cd /opt/stack/apps/medclick-prod && flock -n /tmp/medclick-prod-exports-watchdog.lock docker compose exec -T backend php bin/console app:exports:monitor --watchdog >> /home/deploy/logs/medclick-prod-exports-watchdog.log 2>&1
```

🔒 Dans les 2 minutes : `export_monitor_state.last_watchdog_at` récent, administration « gardien
actif ». Relancer `deploy.sh` ensuite n'affiche plus l'avertissement.

Surveillance du dispositif, sans consultation : le gardien signale un worker arrêté (un e-mail) ;
le worker signale un gardien qui ne passe plus depuis 10 minutes (un e-mail) ; chacun est clos au
retour de l'autre. Si les deux s'arrêtent ensemble (VPS ou Docker arrêté), l'API est elle-même
indisponible : surveillance externe du site (hors périmètre de ce plan).

## Vérifications après déploiement 🤖🔒

| # | Contrôle | Attendu |
|---|---|---|
| V1 | `docker ps` | backend, worker, frontend, db `healthy` ; images aux SHA déployés ; `legacy-apex-sw` en service |
| V2 | `.env` | `BACKEND_TAG`/`FRONTEND_TAG` = SHA déployés ; `docker compose config` sans erreur |
| V3 | Journal du worker | « Consuming messages from transports "exports, scheduler_exports_monitor" » |
| V4 | `https://www.medclick.be/`, `https://medclick.be/`, `/api/terms-conditions` | 200 ; pied de page « Version 3.2.0 » |
| V5 | Administration → Surveillance des exports (après 2 min) | worker actif, gardien actif |
| V6 | Acheminement réel des alertes : `docker compose exec -T backend php bin/console mailer:test <adresse> --from=service@medclick.be --subject="[MedClick] Test d'acheminement des alertes"` | 👤 e-mail reçu (boîte de réception, pas les indésirables), **un seul** |
| V7 | Export réel avec le compte de l'opérateur (👤) : bouton « Excel » | progression réelle, fichier téléchargé, ouvert sans erreur dans Excel, historique « téléchargé » |
| V8 | `GET /api/excel2/{year}` et `/api/excel/{year}` (même compte) | 200, même contenu que V7 (hors date d'édition) |
| V9 | Isolation | export d'un autre utilisateur : 404 ; page admin refusée à un non-admin |
| V10 | `docker stats` pendant V7 | worker < 768 Mo, aucun impact visible sur les autres applications du VPS |
| V11 | `error_log` (administration → erreurs) | aucune nouvelle erreur 500 |
| V12 | Alerte worker (facultatif, 👤 décide) | `docker compose stop worker` 4 min → **un** e-mail « Worker des exports Excel arrêté » ; `docker compose up -d worker` → incident clos |

Un contrôle rouge → diagnostic immédiat ; si non corrigeable en 15 min → retour arrière.

## Surveillance (J0 → J+7)

- Quotidien : page « Surveillance des exports » (échecs, non téléchargés, durées, état du worker et
  du gardien), journal du gardien (vide quand tout va bien), redémarrages du worker
  (`docker inspect -f '{{.RestartCount}}' medclick-prod-worker` : redémarrages normaux toutes les
  heures, `--time-limit`), espace disque du volume des exports (expiration 2 h).
- Journaux Traefik : plus aucune réponse 500 à 30 s sur l'export ; appels restants aux alias
  `/api/excel2`, `/api/excel` (décision de retrait ultérieure).

## Retour arrière

### R1. Application seule (cas normal, aucune donnée perdue) 🤖

1. Restaurer `docker-compose.yml` et `deploy.sh` depuis `backups/…-avant-3.2.0`.
2. `.env` : `BACKEND_TAG`/`FRONTEND_TAG` = images notées à l'étape 1 (elles sont sur le VPS).
3. `docker compose up -d --wait backend frontend` ; `docker rm -f medclick-prod-worker`.
4. Retirer la ligne du gardien de la crontab (sinon elle échouerait chaque minute : la commande
   n'existe pas dans l'ancienne version) ; contrôle : `diff` avec la copie de l'étape 1 vide.
5. Restaurer `src/pwa/frontend` et `src/pwa/.commit` (cohérence du prochain `deploy.sh`).
6. Vérifier V1, V4, V8, V11. Les utilisateurs gardent leur session (mêmes clés JWT).

Préservation des données :

- la base n'est **ni restaurée ni modifiée** : toutes les saisies faites pendant la période 3.2.0
  (interventions, gardes, comptes…) restent en place, dans des tables dont le schéma n'a pas changé ;
- les tables ajoutées restent en base et sont ignorées par l'ancienne version (aucune lecture) ;
  leur suppression (`doctrine:migrations:migrate prev`, 3 fois) se décide plus tard, hors urgence,
  après sauvegarde ;
- seuls les fichiers d'export de moins de 2 h deviennent inaccessibles (régénérables à la demande).

Durée ≈ 5 min.

### R2. Base endommagée (improbable : migrations additives uniquement) 👤🔒

Décision humaine. `restore.sh into` avec le dump de l'étape 1 : **les écritures faites depuis
sont perdues** ; les identifier d'abord (comparaison par table, comme R-A2 de
`docs/RUNBOOK-BASCULE.md`) et les réinjecter avant la restauration.

## Journal d'exécution — production, 28/10/2026 (heures UTC)

Exécuté sur autorisation explicite du responsable. **MedClick 3.2.0 en production depuis 17:29.**

### Versions

| | Avant | Déployé | Origine |
|---|---|---|---|
| Backend (`master`) | `57aae39` | `3df788f` | fusion `--no-ff` de `feature/export-excel-async` (`49e6a0d`), arbre `0311534` identique à celui validé en préproduction |
| Front (`main`) | `b5f6224` | `7b4b4a2` | fusion `--no-ff` de `feature/export-excel-progress` (`86dc29c`), arbre `29d9cf2` identique à celui validé en préproduction |

Préproduction à la validation : backend `49e6a0d`, front `86dc29c` (même contenu).

### Déroulé

| Heure | Étape | Résultat |
|---|---|---|
| ~17:15 | Préflight : conteneurs, disque (51 Go libres), sauvegarde de la nuit (03:50) | SHA-256 OK, `restore.sh test` réussi (base jetable supprimée) |
| 17:24 | Fusions poussées (`master`, `main`) | arbres identiques à ceux validés |
| 17:24 | `backup.sh` (chiffrée, copiée hors VPS) | SHA-256 OK, présente sur le stockage distant |
| 17:25 | Dump local + copies `.env`, `deploy.sh`, `docker-compose.yml`, `.commit`, `src/pwa/frontend`, crontab, liste des images | `backups/*-avant-3.2.0-20261028-172553` |
| ~17:26 | `.env` : `ERROR_ALERT_EMAIL`, `EXPORT_ALERT_EMAIL` | seules ces deux lignes ajoutées, fichier en 600 |
| 17:27 | Front `7b4b4a2` livré, `deploy.sh` de `3df788f` installé, 1er passage | arrêt attendu : 3 migrations en attente |
| ~17:28 | Migrations `Version20261027090000`, `Version20261028090000`, `Version20261029090000` | exécutées ; nouvelles tables présentes ; comptages des tables existantes inchangés |
| 17:29 | 2e passage de `deploy.sh` | backend et worker `3df788f`, front `7b4b4a2`, `legacy-apex-sw` conservé ; étiquettes enregistrées dans `.env` |
| ~17:29 | Ligne du gardien ajoutée à la crontab | `diff` avec la copie = uniquement les lignes du gardien |
| 17:30 | Vérifications | voir ci-dessous |

### Vérifications

Terminées :

- V1–V3 : conteneurs `healthy` aux SHA déployés ; worker « Consuming messages from transports
  "exports, scheduler_exports_monitor" » ; destinataires d'alerte transmis au backend et au worker.
- V4 : `www` 200, apex 301 vers `www`, `/api/terms-conditions` 200 ; « 3.2.0 » dans le module de
  la page d'accueil servi.
- V5 : worker actif (signe de vie chaque minute), gardien actif (passage chaque minute), aucune
  alerte en cours.
- V9 (partiel) : `/api/exports`, `/api/excel2/{year}`, `/api/excel/{year}`, `/api/admin/exports`
  répondent 401 sans jeton. L'isolation entre utilisateurs (18 contrôles) a été vérifiée en
  préproduction sur le même contenu, pas en production (aucun compte de test en production).
- V10 : worker ≈ 38 Mo / 768 Mo au repos, aucun impact visible sur les autres applications.
- V11 : aucune nouvelle entrée dans `error_log` depuis le déploiement.

En attente de confirmation (responsable) :

- V6 : e-mail de test `mailer:test` envoyé à 17:30:41 vers l'adresse des alertes : réception
  (une seule fois, hors indésirables) à confirmer. Seule vérification de la livraison réelle par
  le SMTP de production (la préproduction capture tout dans Mailpit).
- V7 : export réel avec le compte du responsable (progression, téléchargement, ouverture dans Excel).
- V8 : alias `/api/excel2` et `/api/excel` avec le même compte (même contenu que V7).

Non réalisée : V12 (arrêt volontaire du worker en production, facultatif ; vérifié en préproduction).

### Particularités observées

- **Rotation des sauvegardes** : `backup.sh` ne garde qu'une sauvegarde par jour ; l'exécution de
  17:24 a remplacé, localement et à distance, celle de 03:50 (vérifiée juste avant). Lancer
  `backup.sh` deux fois le même jour ne conserve que la dernière.
- **Index hors Doctrine** : `doctrine:schema:validate` signale un écart de schéma en production :
  index `wdbi_name_191_031fbf8277a9` sur `nomenclature.name` (préfixe 191), présent avant le
  déploiement (dump de 17:25), absent du mapping. Laissé en place (recherche dans la nomenclature).
  **Ne jamais lancer `doctrine:schema:update` en production** : il le supprimerait. À intégrer au
  mapping ou à la baseline (ADR-105).
- Avertissement attendu des migrations : « 30 previously executed migrations … not registered »
  (schéma historique géré hors migrations).
- **Premier passage du gardien (17:30:04)** 4 s avant le premier signe de vie du worker : absence
  journalisée pendant le délai de grâce d'un worker jamais vu, **sans e-mail**, close au signe de
  vie suivant. Comportement attendu d'une première installation.

### Points de vigilance (J0 → J+7)

- Surveillance quotidienne listée plus haut (« Surveillance (J0 → J+7) »).
- Appels restants aux alias `/api/excel2`, `/api/excel` (journaux Traefik) avant toute décision
  de retrait (ADR-203).
- Retour arrière possible tant que les copies `backups/*-avant-3.2.0-20261028-172553` et les images
  `57aae39` / `b5f6224` restent sur le VPS : ne pas les supprimer avant la fin de la période de
  surveillance.
