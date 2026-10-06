#!/usr/bin/env bash
# Déploiement MedClick sur le VPS à des SHA explicites (préproduction ou production).
# L'environnement est déduit du dossier : /opt/stack/apps/medclick-<env> → docker-compose.<env>.yml.
# Usage (dans /opt/stack/apps/medclick-<env>) :
#   ./deploy.sh <sha_backend> <sha_frontend>
# Étapes : sources aux SHA demandés → build → up → clés JWT (une fois) → cache → migrations.
# Le dépôt du frontend est privé : ses sources sont livrées dans src/pwa par
#   git archive <sha> frontend | ssh <vps> "tar -x -C .../src/pwa" ; echo <sha> > src/pwa/.commit
# (docs/DEPLOIEMENT-VPS.md), et le script vérifie que .commit correspond au SHA demandé.
# S'arrête à la première erreur. Une migration en attente arrête le déploiement (décision humaine).
set -euo pipefail
cd "$(dirname "$0")"
ENV_NAME=$(basename "$PWD"); ENV_NAME=${ENV_NAME#medclick-}

BACKEND_SHA=${1:?SHA backend requis}
FRONTEND_SHA=${2:?SHA frontend requis}
BACKEND_REPO=https://github.com/Sftaita/medclick-backend.git

checkout() { # <dépôt> <dossier> <sha>
  [ -d "$2/.git" ] || git clone --quiet "$1" "$2"
  git -C "$2" fetch --quiet origin
  git -C "$2" -c advice.detachedHead=false checkout --quiet --force "$3"
  git -C "$2" clean -fdq
  echo "$2 @ $(git -C "$2" rev-parse HEAD)"
}

[ -f .env ] || { echo ".env absent (modèle : src/backend/deploy/vps/.env.$ENV_NAME.example)"; exit 1; }
[ "$(stat -c %a .env)" = "600" ] || { echo ".env doit être en 600"; exit 1; }

mkdir -p src
checkout "$BACKEND_REPO" src/backend "$BACKEND_SHA"
[ "$(cat src/pwa/.commit 2>/dev/null)" = "$FRONTEND_SHA" ] || { echo "src/pwa/.commit ne correspond pas à $FRONTEND_SHA"; exit 1; }
echo "src/pwa @ $FRONTEND_SHA (archive)"
[ -f "src/backend/deploy/vps/docker-compose.$ENV_NAME.yml" ] || { echo "environnement inconnu : $ENV_NAME"; exit 1; }
cp "src/backend/deploy/vps/docker-compose.$ENV_NAME.yml" docker-compose.yml

export BACKEND_TAG="${BACKEND_SHA:0:7}" FRONTEND_TAG="${FRONTEND_SHA:0:7}"
docker compose build
# Mailpit n'existe qu'en préproduction (capture des e-mails).
docker compose up -d --wait db $(docker compose config --services | grep -x mailpit || true)

# Clés JWT de CET environnement (phrase de passe du .env), générées une seule fois dans le volume.
docker compose run --rm --no-deps -e APP_ENV=prod backend sh -c \
  'php bin/console lexik:jwt:generate-keypair --skip-if-exists && chown www-data:www-data config/jwt/*.pem && chmod 600 config/jwt/private.pem && chmod 644 config/jwt/public.pem'

docker compose up -d --wait backend
docker compose exec -T backend php bin/console cache:clear --no-warmup
docker compose exec -T backend php bin/console cache:warmup
docker compose exec -T backend chown -R www-data:www-data var

PENDING=$(docker compose exec -T backend php bin/console doctrine:migrations:status --no-interaction | awk -F'|' '$3 ~ /New/ {gsub(/ /,"",$4); print $4}')
if [ "${PENDING:-0}" != "0" ]; then
  echo "ARRÊT : ${PENDING} migration(s) en attente — à examiner avant toute exécution."
  exit 2
fi

docker compose up -d --wait frontend
docker compose ps
