# Déploiement MedClick sur le VPS (préproduction, puis production)

Remplacera `docs/DEPLOIEMENT.md` (Hostinger mutualisé) après la bascule, qui fera l'objet d'un lot
séparé avec plan de retour arrière. **Aucun secret dans ce document.**

## Architecture

```
Internet ── Traefik (VPS partagé : SurgicalHub, MedVue, MedClick ; Let's Encrypt, réseau « proxy »)
              │   Host(staging.medclick.be), liste d'IP autorisées (préproduction)
              ├── /api/*, /activation/*  →  backend   (FrankenPHP, PHP 8.4, sert UNIQUEMENT public/)
              └── tout le reste          →  frontend  (nginx, build Vite statique, sans Node)
réseau privé medclick-<env>-internal :
   backend ── db (MariaDB 11.8 dédiée, aucun port publié, volume dédié)
   backend ── mailpit (préproduction : capture de TOUS les e-mails ; interface 127.0.0.1:8026)
   worker  ── db (exports Excel : même image, aucun port ni accès entrant, 1 CPU / 768 Mo ;
              volume des fichiers partagé avec le backend — voir docs/EXPORTS.md)
```

- Une seule origine (frontend et API sous le même nom) : pas de CORS en pratique, pas d'URL
  codée en dur dans le build (`VITE_API_URL` = `https://<hôte>/api/`, argument de build).
- Isolation : base, identifiants, volumes et réseau propres à MedClick ; jamais la MySQL partagée
  du VPS, jamais la base de production depuis la préproduction.
- Pas d'authentification HTTP Basic devant l'API : l'en-tête `Authorization` sert au JWT.
  Protection de la préproduction : liste d'IP (`/opt/stack/traefik/dynamic/medclick-staging.yml`).

## Fichiers (dépôt backend, `deploy/vps/`)

| Fichier | Rôle |
|---|---|
| `backend.Dockerfile` | image backend (extensions intl, zip, gd, pdo_mysql, opcache ; sodium intégré) |
| `frontend.Dockerfile`, `frontend-nginx.conf` | build Node 24.21 puis nginx ; `service-worker.js`/`index.html` sans cache, `assets/` immuables, 404 (jamais `index.html`) pour un fichier absent ou sensible |
| `docker-compose.staging.yml` | projet `medclick-staging` (db, backend, frontend, mailpit) |
| `docker-compose.prod.yml`, `.env.prod.example` | projet `medclick-prod` (db, backend, frontend ; SMTP réel ; routes `www` activées par `WWW_LIVE`) — bascule : `docs/RUNBOOK-BASCULE.md` |
| `.env.staging.example` | variables du projet (modèle, sans valeur secrète) |
| `traefik-medclick-staging.yml` | modèle du middleware de liste d'IP |
| `deploy.sh` | déploiement à SHA explicites (environnement déduit du dossier `medclick-<env>`) |
| `backup.sh`, `backup-retention.sh`, `restore.sh` | sauvegarde chiffrée hors VPS (rétention 7/4/6), restauration (test jetable ou réelle) |
| `cra-transition/` | test de transition ancien front CRA → nouveau front Vite |

## Variables d'environnement (`/opt/stack/apps/medclick-<env>/.env`, chmod 600)

| Variable | Rôle |
|---|---|
| `PUBLIC_HOST`, `PUBLIC_URL` | nom et URL publics (ex. `staging.medclick.be`) |
| `CORS_ALLOW_ORIGIN` | expression régulière de l'origine autorisée |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | base dédiée |
| `APP_SECRET` | secret Symfony de l'environnement |
| `JWT_PASSPHRASE` | phrase de passe des clés JWT de l'environnement (jamais celles d'un autre) |
| `BACKEND_TAG`, `FRONTEND_TAG` | enregistrées par `deploy.sh` à chaque déploiement (7 premiers caractères des SHA) ; le Compose refuse de démarrer sans elles : une commande manuelle utilise toujours les images du dernier déploiement |

Le Compose fixe en plus : `APP_ENV=prod`, `APP_DEBUG=0`, `MAILER_DSN=smtp://mailpit:1025`
(préproduction), `PUBLIC_FRONTEND_URL` = `PUBLIC_API_URL` = `PUBLIC_URL` (liens des e-mails et
redirection d'activation ; non définies, ces variables valent la production Hostinger actuelle).
Secrets générés sur le VPS : `openssl rand -hex 32`.

Fuseau horaire : **UTC** partout (conteneurs, PHP, MariaDB), comme la production Hostinger
(vérifié le 06/10/2026) : ne pas le modifier sans migration des dates déjà stockées.

## Premier déploiement

```bash
# Sur le VPS, utilisateur deploy
D=/opt/stack/apps/medclick-staging; mkdir -p $D && cd $D
git clone https://github.com/Sftaita/medclick-backend.git src/backend
cp src/backend/deploy/vps/.env.staging.example .env && chmod 600 .env   # renseigner les secrets
cp src/backend/deploy/vps/traefik-medclick-staging.yml /opt/stack/traefik/dynamic/medclick-staging.yml  # IP autorisées
# Depuis le poste (dépôt frontend privé) :
git -C <pwa-medclick> archive <sha_front> frontend | ssh surgicalhub-prod "mkdir -p $D/src/pwa && tar -x -C $D/src/pwa"
ssh surgicalhub-prod "echo <sha_front> > $D/src/pwa/.commit"
# Base : restaurer une copie contrôlée AVANT le premier démarrage du backend (healthcheck = lecture base)
docker compose -f src/backend/deploy/vps/docker-compose.staging.yml --project-directory . up -d --wait db mailpit
# puis voir « Copie production → préproduction »
cp src/backend/deploy/vps/deploy.sh . && ./deploy.sh <sha_backend> <sha_front>
```

`deploy.sh` : sources aux SHA demandés (le frontend est vérifié par `src/pwa/.commit`), build,
clés JWT générées une seule fois dans le volume (`--skip-if-exists`, privée 600), démarrage avec
attente des healthchecks (base → backend → frontend), cache Symfony, **arrêt si une migration est
en attente** (décision humaine), puis frontend.

## Mise à jour

Backend seul : `./deploy.sh <nouveau_sha_backend> <sha_front_actuel>`.
Frontend : livrer la nouvelle archive dans `src/pwa` + `.commit`, puis `./deploy.sh <sha_backend> <nouveau_sha_front>`.
Retour arrière : relancer `deploy.sh` avec les SHA précédents (images reconstruites ou réutilisées
depuis le cache) ; en cas de migration, restaurer la sauvegarde prise juste avant.

## Copie production → préproduction

1. Dump sur Hostinger selon `docs/DEPLOIEMENT.md` (mysqldump `--single-transaction`), contrôle
   gzip, nombre de tables, nombre de lignes, SHA-256.
2. Transfert en flux direct vers le VPS (rien sur le poste), contrôle du SHA-256 à l'arrivée.
3. Restauration dans la base staging (`mariadb … < dump`), comparaison des comptages.
4. Contrôles : e-mails vers Mailpit, URL publiques staging, clés JWT propres, aucune tâche
   planifiée ni webhook vers l'extérieur.

## Sauvegardes

| Élément | Valeur |
|---|---|
| Contenu | dump MariaDB (`--single-transaction --routines --triggers`) |
| Chiffrement | gpg symétrique AES-256, phrase de passe `~/.medclick-backup-passphrase` (600) — **à conserver aussi hors du VPS** (gestionnaire de mots de passe) |
| Contrôle | déchiffrement + gzip + « Dump completed » immédiatement après ; SHA-256 |
| Local | `~/backups/medclick-<env>/` (700, fichiers 600) |
| Hors VPS | `gdrive:INFORMATIQUE/Base de donnée/medclick-<env>` (rclone existant), copie vérifiée |
| Rétention (local et Drive) | 7 quotidiennes, 4 hebdomadaires, 6 mensuelles (`backup-retention.sh`), appliquée seulement après une copie distante vérifiée |
| Échec | code de sortie ≠ 0, ligne `ERREUR` dans `~/backups/backup.log`, aucun fichier partiel (écriture en `.part`) |
| Fréquence (production) | quotidienne, après les sauvegardes existantes : cron `50 3 * * * /opt/stack/apps/medclick-prod/backup.sh /opt/stack/apps/medclick-prod prod` (CRON_TZ=UTC) |

Restauration :
- test (base jetable sans réseau, supprimée ensuite) : `restore.sh test <fichier.sql.gz.gpg>` ;
- réelle (écrase la base du projet, confirmation demandée) : `restore.sh into <projet> <fichier>`.

## Worker des exports Excel

- Service `worker` des deux Compose : `messenger:consume exports scheduler_exports_monitor`
  (génération des exports et surveillance chaque minute), cache préchauffé au démarrage.
- `deploy.sh` le (re)crée après le contrôle des migrations ; la première mise en service exige la
  migration `Version20261027090000` (tables `export_job`, `export_job_event`, `messenger_messages`).
- Arrêt propre : `docker compose stop worker` laisse finir la génération en cours (120 s).
- Préproduction : `EXPORT_ALERT_EMAIL` (facultatif) → alertes capturées par Mailpit.

## Journaux

- Backend : `docker compose logs backend` ; Symfony `var/log` (volume `medclick_<env>_log`).
- Worker des exports : `docker compose logs worker` (lignes `[export <corrélation>]`).
- Frontend : `docker compose logs frontend` (accès nginx).
- Traefik : `/opt/stack/traefik/logs/{traefik,access}.log`.
- Erreurs applicatives : table `error_log` (`/api/admin/errors`), sans corps de requête ni IP.

## Service worker et mise à jour du frontend

- `service-worker.js` garde la même URL que l'ancien front CRA : le navigateur le remplace.
- Servi sans cache HTTP (`no-cache, no-store`), comme `index.html` ; fichiers `assets/` immuables.
- Un fichier `assets/` absent répond 404 (jamais `index.html` à la place d'un script).
- Mise à jour du nouveau front : invite « Nouvelle version disponible » (jamais forcée).
- **Fichiers de la version précédente conservés** : `deploy.sh` passe l'image du frontend en
  service (`PREVIOUS_FRONTEND_IMAGE`) au build, qui reprend ses fichiers `assets/` (ceux de sa
  liste `.release-assets`, ou tout `assets/` pour une image antérieure à ce mécanisme). Un onglet
  resté ouvert, ou une PWA servie par l'ancien service worker, continue de charger ses pages à la
  demande au lieu d'afficher « Une erreur est survenue ». Une seule version précédente est gardée.
- **Filet côté application** (à partir de la v2) : si un chunk reste introuvable (version plus
  ancienne, retour arrière), la page d'erreur active le service worker publié puis recharge,
  une seule fois par fenêtre de 30 s (`src/app/versionRecovery.js` du frontend).
- Build manuel (hors `deploy.sh`) : passer `--build-arg PREVIOUS_IMAGE=<image en service>`, sinon
  rien n'est repris (image nginx nue par défaut).

### Transition depuis l'ancien front CRA (mesurée au LOT 3)

Test local reproductible : `cra-transition/transition-server.mjs` (une origine, `mode.txt` = `cra`
ou `vite`, `delay.txt` = latence en ms), avec un miroir de l'ancien front et le build Vite.

| Scénario | Résultat |
|---|---|
| Ancien front ouvert, serveur remplacé, rechargement | ancien front affiché (cache du SW CRA), nouveau SW installé puis **en attente** ; aucune page blanche |
| Tous les onglets fermés puis réouverture | nouveau front, contrôlé par le nouveau SW |
| Un ancien onglet (ou PWA installée) reste ouvert | les nouveaux onglets reçoivent encore l'ancien front, fonctionnel, jusqu'à fermeture du dernier |
| Connexion lente (2 s par requête) | ancien front instantané ; installation en arrière-plan (~20 s) ; nouveau front ensuite servi du cache (< 0,2 s) |
| Anciens liens `/#/login`, `/#/resetPassword/<jeton>` | redirigés vers les routes du nouveau front |

Décision : **aucune prise de contrôle forcée** (`skipWaiting`/`clientsClaim` à l'installation).
L'ancien front charge ses écrans à la demande (`/static/js/*.chunk.js`) ; un SW Vite activé de
force sous un onglet CRA ouvert ferait échouer ces chargements (le nouveau serveur ne les a plus).
Conséquence pour la bascule : pendant la période de transition, l'ancien front encore ouvert
appelle toujours `https://api-medclick.easymed.fun/api/` : ce nom doit continuer à servir l'API.

### Ancien front CRA resté sur l'apex `medclick.be` (corrigé le 27/10/2026)

L'ancien front était servi aussi sur `https://medclick.be` (origine distincte de `www`). Après la
bascule B, l'apex redirige tout vers `www`, y compris `/service-worker.js` : les navigateurs
refusent alors de mettre à jour le SW CRA (« script resource is behind a redirect ») et celui-ci
continue de servir l'ancienne version depuis son cache, sans limite de durée.

Correctif : service `legacy-apex-sw` de `docker-compose.prod.yml` (nginx, routeur Traefik
`Host(medclick.be) && Path(/service-worker.js)`, priorité 1000). Il sert en 200, sans cache HTTP,
un SW de remplacement qui :
- s'active aussitôt et sert encore, depuis le cache, les fichiers d'un onglet CRA ouvert ;
- bascule vers `www` les onglets en arrière-plan ; l'onglet affiché bascule à son prochain
  chargement (pas de rechargement forcé : une saisie en cours n'est pas perdue) ;
- redirige toute navigation vers `https://www.medclick.be` (chemin, paramètres et ancre conservés) ;
- une fois plus aucun onglet ouvert sur l'apex : supprime les seuls caches du SW CRA
  (`workbox-precache*`, `workbox-runtime*`, `images`) et se désinscrit. `localStorage` et IndexedDB
  ne sont pas touchés.

En pratique : à la première ouverture, l'ancien front s'affiche une dernière fois ; à la suivante,
c'est le nouveau. Vérifié sur un banc local (miroir CRA) puis en production dans un Chrome qui
avait l'ancienne PWA. Les autres URL de l'apex restent redirigées (301/308), `www` est inchangé.

Application sans redémarrage des autres services (hash Compose de `db`, `backend`, `frontend`
inchangés) : sauvegarder `docker-compose.yml`, le remplacer, puis
`docker compose up -d --no-deps --wait legacy-apex-sw`.

`deploy.sh` ne le supprime jamais (aucun `down` ni `--remove-orphans`) et le recrée s'il a disparu ;
un conteneur à jour n'est pas redémarré.

Retour arrière : `docker compose rm -sf legacy-apex-sw` (le routeur disparaît, l'apex redirige à
nouveau tout), puis remettre le `docker-compose.yml` sauvegardé. Les navigateurs déjà migrés ne
sont pas concernés.

Retrait (pas avant plusieurs mois) : seul un navigateur qui a encore l'ancien SW demande ce fichier
sur l'apex. Retirer quand le compteur reste à zéro pendant 30 jours consécutifs :
`grep medclick-prod-apex-sw@docker /opt/stack/traefik/logs/access.log | tail` (date de la dernière
requête). Puis : retirer le service `legacy-apex-sw` et ses deux `configs` de
`docker-compose.prod.yml` (PR), copier le fichier sur le VPS et lancer
`docker compose rm -sf legacy-apex-sw`. Aucun autre service n'est touché.
