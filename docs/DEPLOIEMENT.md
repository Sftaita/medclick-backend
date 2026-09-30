# Déploiement en production

## Infrastructure

- Hébergement mutualisé **Hostinger** (LiteSpeed), PHP **8.2**, MySQL.
- Accès SSH : port `65002`, compte de l'hébergement `easymed.fun` (voir `~/.ssh/config`).
- API : `https://api-medclick.easymed.fun` → racine web `backend/public/`.
- Code : `~/domains/easymed.fun/public_html/medclick/backend`, **clone Git de `master`**.
- Front : `frontend/` et `admin-Frontend/` dans le même dossier `medclick/`.

⚠️ Le projet est **sous `public_html`** : tout ce qui n'est pas dans `public/` serait servi par
le web sans les `.htaccess` « Require all denied » posés dans `config/`, `var/`, `src/`,
`vendor/`, `docs/`, etc. et le filtre du `.htaccess` racine. Ils ne sont pas versionnés :
**ne pas les supprimer**. Vérification :
`curl -I https://easymed.fun/medclick/backend/config/jwt/private.pem` doit répondre 403.
À terme, déplacer le projet hors de `public_html` et ne pointer que `public/`.

## Fichiers propres à la production (non versionnés)

- `.env` (`APP_ENV=prod`, `APP_DEBUG=0`, secrets).
- `public/.htaccess` modifié localement (CORS pour `frontend.easymed.fun` et `medclick.be`,
  redirection HTTPS) : `git pull` le conserve tant que le fichier n'est pas modifié dans le dépôt.
- `config/jwt/*.pem` (régénérées le 30/09/2026 après exposition publique de l'ancienne clé).

## Procédure

```bash
ssh -p 65002 <compte>@<ip>
cd ~/domains/easymed.fun/public_html/medclick/backend

# 1. Sauvegardes (hors du web)
B=~/backups/medclick-$(date +%Y%m%d-%H%M); mkdir -p $B && chmod 700 $B
mysqldump --defaults-extra-file=<fichier .my.cnf> --single-transaction --no-tablespaces <base> | gzip > $B/db.sql.gz
cp -a vendor $B/vendor; cp .env composer.lock $B/; git rev-parse HEAD > $B/git-head

# 2. Code (échoue proprement si un fichier a été modifié à la main : ne jamais éditer en prod)
git pull --ff-only origin master

# 3. Dépendances — l'extension sodium est absente du PHP CLI d'Hostinger ; lcobucci/jwt
#    l'exige mais dispose d'un repli natif (l'app signe en RS256, sans sodium).
composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --ignore-platform-req=ext-sodium

# 4. Migrations : exécuter explicitement les nouvelles (d'anciennes migrations « not available »
#    figurent en base et font poser une question à doctrine:migrations:migrate)
php bin/console doctrine:migrations:list
php bin/console doctrine:migrations:execute 'DoctrineMigrations\VersionXXXXXXXXXXXXXX' --up --no-interaction

# 5. Cache
rm -rf var/cache/prod && php bin/console cache:clear --env=prod

# 6. Vérifications
curl -s -o /dev/null -w "%{http_code}\n" https://api-medclick.easymed.fun/api/terms-conditions   # 200
curl -s -o /dev/null -w "%{http_code}\n" https://api-medclick.easymed.fun/api/years              # 401
```

Retour arrière : `git checkout $(cat $B/git-head)`, restaurer `vendor/` et `composer.lock`
depuis `$B`, puis vider le cache (et `zcat $B/db.sql.gz | mysql ...` si une migration pose problème).

## Journal

- **30/09/2026** — Déploiement des correctifs de sécurité P0 + Symfony 5.4 (`76dc56a`).
  Coupure de quelques minutes (erreur 500) : `composer install` avait échoué sur `ext-sodium`
  après le `git pull`. Constats traités : clé JWT privée et `var/log/dev.log` (30 Go)
  téléchargeables publiquement → accès bloqués, clés régénérées ; `APP_DEBUG=true` → `0`.
  Correctif manuel en prod dans `TokenActivatorController` repris dans le dépôt.
  Sauvegarde : `~/backups/medclick-20260930/`.
