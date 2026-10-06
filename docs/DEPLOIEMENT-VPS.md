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
| `.env.staging.example` | variables du projet (modèle, sans valeur secrète) |
| `traefik-medclick-staging.yml` | modèle du middleware de liste d'IP |
| `deploy.sh` | déploiement à SHA explicites |
| `backup.sh`, `restore.sh` | sauvegarde chiffrée hors VPS, restauration (test jetable ou réelle) |
| `cra-transition/` | test de transition ancien front CRA → nouveau front Vite |

## Variables d'environnement (`/opt/stack/apps/medclick-<env>/.env`, chmod 600)

| Variable | Rôle |
|---|---|
| `PUBLIC_HOST`, `PUBLIC_URL` | nom et URL publics (ex. `staging.medclick.be`) |
| `CORS_ALLOW_ORIGIN` | expression régulière de l'origine autorisée |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | base dédiée |
| `APP_SECRET` | secret Symfony de l'environnement |
| `JWT_PASSPHRASE` | phrase de passe des clés JWT de l'environnement (jamais celles d'un autre) |
| `BACKEND_TAG`, `FRONTEND_TAG` | posées par `deploy.sh` (7 premiers caractères des SHA) |

Le Compose fixe en plus : `APP_ENV=prod`, `APP_DEBUG=0`, `MAILER_DSN=smtp://mailpit:1025`
(préproduction), `PUBLIC_FRONTEND_URL` = `PUBLIC_API_URL` = `PUBLIC_URL` (liens des e-mails et
redirection d'activation ; non définies, ces variables valent la production Hostinger actuelle).
Secrets générés sur le VPS : `openssl rand -hex 32`.

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

## Journaux

- Backend : `docker compose logs backend` ; Symfony `var/log` (volume `medclick_<env>_log`).
- Frontend : `docker compose logs frontend` (accès nginx).
- Traefik : `/opt/stack/traefik/logs/{traefik,access}.log`.
- Erreurs applicatives : table `error_log` (`/api/admin/errors`), sans corps de requête ni IP.

## Service worker et mise à jour du frontend

- `service-worker.js` garde la même URL que l'ancien front CRA : le navigateur le remplace.
- Servi sans cache HTTP (`no-cache, no-store`), comme `index.html` ; fichiers `assets/` immuables.
- Un fichier `assets/` absent répond 404 (jamais `index.html` à la place d'un script).
- Mise à jour du nouveau front : invite « Nouvelle version disponible » (jamais forcée).

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
