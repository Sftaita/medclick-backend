# Runbook — bascule de MedClick d'Hostinger vers le VPS (LOT 4)

**Statut : à valider.** Rien de ce document n'est exécuté sans accord explicite, étape par étape
pour les étapes marquées 🔒. Aucun secret ici : les secrets vivent dans les `.env` (600) des serveurs.

## Principes

- **Deux bascules séparées**, chacune réversible seule :
  - **A — API et données** : `api-medclick.easymed.fun` → VPS. Les fronts actuels (CRA sur
    `www.medclick.be`, `medclick.be`, `frontend.easymed.fun`, ancien admin) restent sur Hostinger
    et appellent l'API du VPS. Seule étape avec gel des écritures et copie de la base.
  - **B — frontend** (au plus tôt 48 h après A stable) : `www.medclick.be` et `medclick.be` → VPS
    (nouveau front Vite). Aucune donnée ne bouge.
- **Une seule base active à tout instant** : pendant A, l'API Hostinger répond 503 (maintenance)
  dès le dump final et jusqu'au retour arrière éventuel ; elle ne reçoit plus d'écriture.
- Hostinger reste intact et prêt (code, base figée, fronts) pendant toute la période de garde.
- Créneau : nuit de semaine (trafic minimal), annoncé aux utilisateurs.
- Ne jamais toucher aux enregistrements **MX / mail** de `medclick.be` et `easymed.fun`.

## Rôles et notations

- 👤 action de l'utilisateur (DNS hPanel, mots de passe, validation) ; 🤖 action préparée/exécutée
  par l'assistant ; 🔒 nécessite un accord explicite au moment de l'exécution.
- `H` = Hostinger (`~/domains/easymed.fun/public_html/medclick/backend`, PHP `/opt/alt/php84/usr/bin/php`) ;
  `V` = VPS (`/opt/stack/apps/medclick-prod`, utilisateur `deploy`).
- SHA à déployer (à figer au préflight) : backend = un SHA de `master` contenant `deploy/vps/`
  (aujourd'hui seulement sur `feature/vps-staging`, qui contient `master` ≥ `cc66e96` : fusion à
  décider avant A ; `deploy.sh` exige ces fichiers dans le SHA déployé), frontend `46912de` ou
  successeur validé sur le staging.

## Décisions figées (ne pas rediscuter pendant la bascule)

| Sujet | Décision (06/10/2026) |
|---|---|
| Fuseau horaire | **UTC** partout (conteneurs, PHP, MariaDB), identique à Hostinger. Aucun réglage `TZ`/`date.timezone` à modifier. |
| Migration historique `Version20241230061340` | Non fusionnée avant A (branche `feature/migration-historique-20241230061340`, `a721d0f`) ; fichier non suivi laissé en place sur H ; réconciliation **après** A. |
| Sauvegarde production VPS | cron autorisé, installé à la création de `medclick-prod` : `CRON_TZ=UTC` / `50 3 * * * /opt/stack/apps/medclick-prod/backup.sh /opt/stack/apps/medclick-prod prod` |
| Phrase de passe des sauvegardes | conservation hors VPS **à confirmer par l'utilisateur avant A** |
| Protection du staging | liste d'IP conservée ; oauth2-proxy plus tard (non requis pour la bascule) |
| `var/log/dev.log` Hostinger (30,4 Go, figé depuis le 01/07/2025) | conservé ; **à supprimer avant la fermeture d'Hostinger** ; STOP si l'espace disque devient critique avant |
| Bascule B | jamais de répétition destructive du front de production ; ≥ 48 h après A ; rollback frontend indépendant du rollback API |

## Tableau de bord des critères

| Mesure | GO | NO-GO / STOP |
|---|---|---|
| Comptages des 16 tables H ↔ V | identiques | une différence |
| Empreinte des données (dump ordonné, sans en-têtes) | identique | différente |
| Migrations en attente sur V | 0 | ≥ 1 |
| Endpoints de fumée | tous au code attendu | un seul écart |
| `error_log` / journaux V (30 min après) | aucune nouvelle erreur 5xx | erreur 5xx récurrente |
| Certificat HTTPS des noms basculés | valide (Let's Encrypt) | absent > 15 min |

---

## 0. Préflight (J-7 à J-1)

| # | Action | Mécanisme | Vérification | STOP si |
|---|---|---|---|---|
| 0.1 | Prérequis fermés | hotfix conditions générales en prod, décision migration historique, sauvegardes, reboot VPS, accès staging | rapport LOT 4 phases 0–5 | un point « à corriger avant bascule » ouvert |
| 0.2 | Figer les SHA | `git rev-parse origin/master` ; SHA frontend validé sur staging | CI verte sur les deux SHA | CI rouge |
| 0.3 | Répétition générale complète sur le staging 🤖 | dump Hostinger → restauration staging → contrôles d'intégrité de l'étape 5 → tests API + Chrome | mêmes critères qu'en réel ; durée mesurée | un critère KO |
| 0.4 | Créer `/opt/stack/apps/medclick-prod` 🤖🔒 | `mkdir`, clone backend au SHA, archive frontend + `.commit`, `.env` depuis `.env.prod.example` (secrets générés sur V, `MAILER_DSN`/`ERROR_ALERT_EMAIL` copiés H→V par flux SSH direct, jamais affichés) | `stat -c %a .env` = 600 ; `docker compose config -q` | erreur de config |
| 0.5 | Construire les images et démarrer **uniquement** `db` 🤖🔒 | `docker compose build` ; `up -d --wait db` | `docker compose ps` db healthy | build KO |
| 0.6 | Joignabilité SMTP depuis V | `bash -c '</dev/tcp/smtp.hostinger.com/465'` | joignable | bloqué |
| 0.7 | Réduire les TTL 👤🔒 (≥ 24 h avant A) | hPanel DNS : `api-medclick.easymed.fun` 1800 → **300** ; noter les valeurs actuelles | `nslookup -debug` affiche 300 | — |
| 0.8 | Copie de rodage H → V (non définitive) 🤖🔒 | comme l'étape 4 sans gel | comptages ; durée totale mesurée | — |
| 0.9 | Vérifier les accès | SSH H et V, hPanel (DNS), boîte mail de supervision | connexion OK | un accès manquant |

## A. Bascule de l'API et des données (fenêtre ≈ 30–45 min)

### 1. Sauvegarde finale Hostinger 🤖🔒

- Action : procédure `docs/DEPLOIEMENT.md` étape 1 (dump `--single-transaction`, `vendor/`, `.env`,
  `composer.lock`, `git-head`) dans `~/backups/medclick-<date>-pre-bascule/`.
- Vérification : `gzip -t`, « Dump completed », 16/16 tables, comptages = base, SHA-256 noté.
- STOP : dump incomplet. Rollback : aucun (rien n'a changé).

### 2. Gel des écritures sur Hostinger 🤖🔒

- Action : copie de sauvegarde de `public/.htaccess`, puis insertion en tête :
  ```apache
  # MAINTENANCE BASCULE — à retirer en cas de retour arrière
  RewriteEngine On
  RewriteRule ^ - [R=503,L]
  Header always set Retry-After "1800"
  ```
- Vérification : `curl https://api-medclick.easymed.fun/api/terms-conditions` → 503 ; aucune
  écriture : `SELECT MAX(id)` des tables `connection_history`, `surgeries` stables sur 2 min.
- STOP : écriture constatée après le gel. Rollback : restaurer `.htaccess` sauvegardé (≤ 1 min).

### 3. Dump final (après gel)

- Action 🤖 : nouveau dump identique à l'étape 1 (`-post-gel`), comptages par table enregistrés
  dans `counts-H.txt`, empreinte des données :
  `mysqldump --no-create-info --skip-comments --order-by-primary --skip-extended-insert --skip-dump-date <base> | sha256sum`.
- STOP : écart entre comptages de l'étape 1 et 3 inexpliqué (normal : seules les écritures
  survenues entre 1 et 2).

### 4. Copie vers le VPS 🤖

- Action : flux direct `ssh H "cat dump.sql.gz" | ssh V "cat > /opt/stack/apps/medclick-prod/restore/final.sql.gz"`,
  SHA-256 comparé ; restauration dans `medclick-prod-db` (`zcat | mariadb`).
- Vérification : SHA-256 identique avant/après transfert.
- STOP : SHA différent → recommencer le transfert.

### 5. Contrôles d'intégrité 🤖

- Comptages des 16 tables V = `counts-H.txt`.
- Empreinte des données V (même commande que 3) = empreinte H.
- `doctrine_migration_versions` identique ; `doctrine:migrations:status` → `New = 0`.
- STOP : un écart → **NO-GO**, rollback A (étape R-A1).

### 6. Déploiement des versions exactes 🤖🔒

- Action : `./deploy.sh <sha_backend> <sha_frontend>` dans `/opt/stack/apps/medclick-prod`
  (clés JWT propres au VPS générées une fois ; cache ; arrêt si migration en attente).
  `.env` : `WWW_LIVE=false` → seul `api-medclick.easymed.fun` est routé par Traefik ; le frontend
  tourne sans route (aucune demande de certificat pour `www`/`medclick.be`, encore chez Hostinger).
- Vérification : 3 conteneurs healthy ; depuis V avec résolution forcée
  (`curl --resolve api-medclick.easymed.fun:443:127.0.0.1 -k`) : `/api/terms-conditions` 200,
  `/api/years` 401.
- STOP : conteneur non healthy, migration en attente.
- Note : les jetons émis par Hostinger deviennent invalides (clés différentes) → les utilisateurs
  se reconnectent une fois (session expirée gérée par les fronts).

### 7. DNS de l'API 👤🔒

- Action (hPanel, zone `easymed.fun`) : `api-medclick` A `91.108.115.96` → **`187.124.55.15`**, TTL 300.
  Ne rien changer d'autre (ni `easymed.fun`, ni `frontend`, ni MX).
- Vérification : `nslookup api-medclick.easymed.fun 8.8.8.8 / 1.1.1.1 / 9.9.9.9` → 187.124.55.15.

### 8. Certificat HTTPS 🤖

- Mécanisme : Traefik obtient le certificat Let's Encrypt (HTTP-01) dès que le DNS résout vers V.
  Si un échec ACME antérieur bloque, redémarrer **uniquement** `medclick-prod-backend` (comme
  pour le staging) ; attention à la limite de 5 échecs/h.
- Vérification : `openssl s_client -servername api-medclick.easymed.fun` → émetteur Let's Encrypt.
- STOP : pas de certificat après 15 min → rollback A.

### 9. Tests fonctionnels immédiats 👤🤖

- API : endpoints publics/protégés (200/401/403), CORS pour `https://www.medclick.be`,
  `https://medclick.be`, `https://frontend.easymed.fun` ; fichiers sensibles 404.
- Ancien front CRA réel (`www.medclick.be`, Hostinger) : connexion d'un compte de test créé pour
  l'occasion, années, intervention (création/modification), favoris, consultation, garde,
  formation, exports `excel2`/`excel`, conditions générales, reset password (e-mail réellement
  reçu, lien vers `https://www.medclick.be/#/resetPassword/…`), activation (lien
  `https://api-medclick.easymed.fun/activation/…` → `https://www.medclick.be/#/login`).
- Ancien admin : tableau de bord, utilisateurs.
- Suppression du compte de test ensuite.
- STOP : un parcours principal KO → rollback A.

### 10. Surveillance (J0 → J+2) 🤖

- `docker compose logs backend`, `var/log/prod-*.log`, table `error_log`, `access.log` Traefik
  (codes 5xx), `docker stats`, RAM/swap.
- Sauvegarde : cron `medclick-prod` (décision figée ci-dessus) installé après l'étape 9, après
  vérification que `backup.sh`/`backup-retention.sh` déployés sont ceux testés (SHA-256),
  permissions 700, destination Drive `medclick-prod` ; test contrôlé (fichier local + distant,
  journal, code de sortie 0).
- STOP : 5xx récurrente non comprise → rollback A.

## B. Bascule du frontend (≥ 48 h après A stable)

### 11. Préparation

- TTL `www` (CNAME CDN, 300) et `medclick.be` (vérifier, cible 300) 👤 ; SHA frontend inchangé ou
  revalidé sur staging ; nouveau front déjà en service sur V (étape 6).

### 12. DNS du frontend 👤🔒

- `www.medclick.be` : CNAME `www.medclick.be.cdn.hstgr.net` → **A `187.124.55.15`** ;
  `medclick.be` : A → `187.124.55.15` (redirection vers `www` par Traefik).
  Attention : désactiver si besoin le CDN Hostinger du site `medclick.be` dans hPanel pour que la
  zone ne soit pas réécrite ; ne pas toucher MX/TXT (SPF/DKIM) de `medclick.be`.
- Puis 🤖 : `.env` `WWW_LIVE=true` et `docker compose up -d frontend` (recréation : Traefik active
  les routes `www`, `www/api` et la redirection de `medclick.be`, puis demande les certificats).
- Vérification : résolution publique ; certificats LE pour `www.medclick.be` et `medclick.be`.
- Rollback immédiat côté VPS : `WWW_LIVE=false` + `docker compose up -d frontend`.

### 13. Tests et transition CRA → Vite

- Parcours utilisateur et admin complets sur `https://www.medclick.be` (comme LOT 3).
- Service worker : comportement validé (pas de prise de contrôle forcée) ; les onglets CRA encore
  ouverts continuent d'appeler `api-medclick.easymed.fun` → **ce nom reste servi par V**.
- `frontend.easymed.fun` et l'ancien admin restent sur Hostinger (lecture/écriture via l'API V).

## Répétition générale de A sur le staging (06/10/2026, 05:18–05:30 UTC)

Copie de production lue en lecture seule (empreintes avant/après le dump identiques), backend
`6783a12`, frontend `46912de`. Aucune écriture dans la base Hostinger, aucun DNS modifié.

| Étape | Mesure |
|---|---|
| Dump Hostinger (`--single-transaction`) | 1,2 s — 5,26 Mo gzip / 40,6 Mo SQL, 16 tables |
| Transfert H → V (flux SSH, SHA-256 vérifié) | 4,0 s |
| Recréation de la base + import | 0,5–1,1 s + 6,3–7,1 s |
| Empreinte d'intégrité V (16 tables) | 1,3 s — **identique** à H (lignes, MIN/MAX id, SUM/XOR CRC32) |
| `deploy.sh` (images en cache) | 50 s — 0 migration (`New = 0`, 0,3 s) |
| Tests API automatisés (75 contrôles) | 18 s — 75/75 |
| Retour arrière (restauration chiffrée + version précédente) | 5,0 s + 49 s — empreinte = état initial, 14/14 contrôles |

Durée de gel estimée pour A : **≈ 3 min techniques** (gel → V prêt), **10–15 min** d'indisponibilité
en écriture avec le DNS (TTL 300) et le certificat, **fenêtre annoncée 45 min** (tests et marge de
retour arrière). Outils : empreinte par table sans donnée affichée,
`deploy/vps/fingerprint.sql` (versionné) ; contrôles API de fumée : scripts de test du LOT 3/4 (hors dépôt, comptes de test).

## Rollback

### R-A1. Avant le changement DNS (étapes 1–6)

- Retirer la maintenance (`.htaccess` sauvegardé) → Hostinger reprend, aucune donnée perdue
  (le VPS n'a reçu aucun trafic). `docker compose down` sur V (volumes conservés pour analyse).

### R-A2. Après le changement DNS (étapes 7–10)

1. Remettre la maintenance 503 sur V (ou arrêter `medclick-prod-backend`) pour geler V.
2. **Écritures faites sur V** : comparer V à la base gelée H par table :
   - comptages et `MAX(id)` ; lignes `id > MAX(id)` côté H = créations sur V ;
   - modifications : tables avec `updated_at` (sinon empreinte par ligne) ;
   - exporter ces lignes (`mysqldump --where "id > N"`) et les réinjecter dans H **avant** de
     retirer la maintenance H ; décision humaine si conflit (aucun conflit possible : H gelé).
   - Si V n'a reçu aucune écriture (cas d'un échec rapide) : rien à reporter.
3. DNS `api-medclick` → `91.108.115.96` 👤 ; retirer la maintenance H ; vérifier.
4. Les utilisateurs se reconnectent (clés JWT différentes).

### R-B. Frontend

- DNS `www` → CNAME CDN Hostinger, `medclick.be` → IP d'origine 👤. Aucune donnée en jeu.
- Les navigateurs ayant installé le service worker Vite le garderont : il n'est pas bloquant (il
  sert l'app depuis son cache et appelle `www.medclick.be/api/` → **à router vers l'API V** tant
  que des clients Vite existent ; prévoir, avant un R-B, une règle Hostinger ou laisser `www`
  pointé sur V et ne revenir qu'en cas de défaut majeur).

## Après la bascule

| Échéance | Action |
|---|---|
| J+2 | Bascule B si A stable (critères ci-dessus) |
| J+7 | Restaurer la sauvegarde VPS de la veille dans une base jetable (`restore.sh test`) |
| J+14 | Remonter les TTL (3600) ; relire `error_log` |
| J+30 au plus tôt | Retrait d'Hostinger : dernière sauvegarde complète archivée (chiffrée, hors serveur), suppression des fichiers sensibles (`dev.log`, `.env`), puis arrêt de l'hébergement MedClick — **seulement** après accord, en conservant `easymed.fun` tant que `api-medclick` et `frontend` y sont définis |
| Après retrait | `api-medclick.easymed.fun` reste un nom servi par V tant que des clients CRA/admin anciens existent (journaux Traefik pour le mesurer) |

## Points ouverts à trancher avant exécution

1. Fusion de `feature/vps-staging` dans `master` (fichiers de déploiement VPS, URL publiques
   configurables `27780a1` — défauts = valeurs de production, sans effet sur H).
2. Ancien admin (`easymed.fun/medclick/admin-Frontend`) : conservé jusqu'au retrait d'Hostinger.
