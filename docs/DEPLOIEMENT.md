# Déploiement en production

## Infrastructure

- Hébergement mutualisé **Hostinger** (LiteSpeed), MySQL. PHP **8.4 obligatoire** depuis
  Symfony 8 (réglage hPanel → Avancé → Configuration PHP ; binaire CLI :
  `/opt/alt/php84/usr/bin/php`). Vérifier `php -v` en SSH avant tout `composer install`.

### Premier déploiement de Symfony 8 (branche `migration/symfony-8`)

1. Sauvegardes (voir procédure), en particulier `vendor/` : c'est le retour arrière.
2. hPanel : passer le site `easymed.fun` en **PHP 8.4**, puis enchaîner immédiatement la
   procédure (la compatibilité de l'ancienne version avec PHP 8.4 n'a pas été vérifiée).
   Retour arrière : repasser en PHP 8.2 et restaurer `vendor/` + `git checkout` de la sauvegarde.
3. Suivre la procédure ci-dessous (aucune migration de base de données dans cette version).
4. Vérifier en plus : `curl -s https://api-medclick.easymed.fun/api/years -H "Authorization: Bearer <jeton>"`
   contient `hydra:member` (le front en dépend).
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

## Capture des erreurs

- Variable `.env` de prod (optionnelle) : `ERROR_ALERT_EMAIL=adresse@exemple` → un email à chaque
  nouvelle erreur (ou erreur résolue qui réapparaît).
- Tâche planifiée quotidienne (RGPD, conservation 90 jours) :
  `/opt/alt/php84/usr/bin/php ~/domains/easymed.fun/public_html/medclick/backend/bin/console app:errors:purge --env=prod`
- Secours si la base est indisponible : `var/log/prod-AAAA-MM-JJ.log` (30 jours).

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
- **01/10/2026** — Déploiement de **Symfony 8.1 / API Platform 4 / Doctrine ORM 3** (`f1add9f`)
  après passage du site `easymed.fun` en PHP 8.4 dans hPanel. Sans coupure (retour arrière
  automatique prévu, non déclenché). Tests de fumée OK (accès public/protégé, CORS, activation,
  validation, fichiers sensibles en 403). Sauvegarde : `~/backups/medclick-20261001-0510/`.
  `DATABASE_URL` de prod sans `serverVersion` : DBAL détecte MariaDB 11.8 à la connexion.
- **04/10/2026** — Hotfix LOT 2D.1 (`4bb701b`, cherry-pick seul de `d60b77f` sur `46a59fe`) :
  synchronisation nomenclature/code/spécialité au PUT d'une intervention. Sans migration ni
  correction de données ; LOT 1 non déployé. Contrôles OK (200/401/403, CORS), aucune nouvelle
  erreur. Sauvegarde : `~/backups/medclick-20261004-1456/`. À reprendre lors de l'intégration
  de LOT 1/LOT 2 (le même correctif y existe sous `d60b77f`).
- **04/10/2026** — LOT 2D.2 (`2ad8e27`) : commande `app:repair-surgery-nomenclature`, puis
  `--apply` (A1) : 2 583 interventions réalignées, 0 ignorée, rollback non utilisé. Sauvegarde
  `~/backups/medclick-20261004-1557-pre-2d2/`, snapshot et journal `~/backups/repair-2d2-20261004/`
  (copiés hors serveur).
- **04/10/2026** — LOT 2D.3 : spécialité « favorites » remplacée par celle de la nomenclature au PUT,
  portée `--scope=favorites` (dry-run uniquement, application soumise à accord), journal en 0600.
  Sans migration.
