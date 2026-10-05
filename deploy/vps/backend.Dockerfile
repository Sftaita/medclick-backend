# Backend MedClick (Symfony 8.1, PHP 8.4) pour le VPS — préproduction et future production.
# Construit depuis la racine du dépôt backend : docker build -f deploy/vps/backend.Dockerfile .
# FrankenPHP sert UNIQUEMENT public/ (racine web /app/public) : code, .env, vendor, clés JWT et
# var/ ne sont jamais accessibles par HTTP (contrairement à l'hébergement mutualisé actuel).

FROM dunglas/frankenphp:1-php8.4 AS vendor

RUN install-php-extensions intl zip gd opcache pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
# --no-scripts : cache:clear et assets ont besoin de l'environnement réel (DB, secrets), fourni
# seulement au démarrage du conteneur. Ils sont lancés par deploy/vps/deploy.sh après « up -d ».
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

FROM dunglas/frankenphp:1-php8.4

# Extensions exigées par le projet (composer check-platform-reqs) : intl, zip, gd (PhpSpreadsheet),
# pdo_mysql, opcache. sodium est compilé dans l'image PHP officielle (lcobucci/jwt l'exige ; il
# manquait au PHP CLI d'Hostinger).
RUN install-php-extensions intl zip gd opcache pdo_mysql

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN php -r 'exit(extension_loaded("sodium") ? 0 : 1);' \
    # .env minimal : les vraies valeurs viennent des variables d'environnement du conteneur.
    && printf 'APP_ENV=prod\n' > .env \
    && mkdir -p var config/jwt \
    && chown -R www-data:www-data var config/jwt

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80

EXPOSE 80

# Prêt = PHP répond ET la base est joignable (route publique qui lit la base).
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD curl -fsS -o /dev/null http://localhost/api/terms-conditions || exit 1
