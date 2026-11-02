#!/usr/bin/env bash
# Déploiement MedClick sur le VPS à des SHA explicites (préproduction ou production).
# L'environnement est déduit du dossier : /opt/stack/apps/medclick-<env> → docker-compose.<env>.yml.
# Usage (dans /opt/stack/apps/medclick-<env>) :
#   ./deploy.sh <sha_backend> <sha_frontend>            déploiement complet puis vérification finale
#   ./deploy.sh --migrate <sha_backend> <sha_frontend>  sauvegarde puis migrations SEULES, exécutées par
#                                                       le nouveau code dans un conteneur temporaire ;
#                                                       backend, worker et frontend en service inchangés
#   ./deploy.sh --verify <sha_backend> <sha_frontend>   vérification finale seule (aucune modification)
# Étapes : sources aux SHA demandés → build → VERROU DE SCHÉMA (nouveau code, conteneur temporaire)
# → étiquettes dans .env → clés JWT (une fois) → up → cache → worker → frontend → vérification finale.
#
# Verrou de schéma : si le nouveau code a une migration en attente, le script s'arrête (code 2) AVANT
# de remplacer le moindre service et avant d'enregistrer les nouvelles étiquettes dans .env : le
# backend et le worker en service restent ceux de la version précédente (un « docker compose up »
# manuel aussi). Procédure : ./deploy.sh --migrate (migration additive, compatible avec la version
# en service), contrôle de la version en service, puis ./deploy.sh.
# Le dépôt du frontend est privé : ses sources sont livrées dans src/pwa par
#   git archive <sha> frontend | ssh <vps> "tar -x -C .../src/pwa" ; echo <sha> > src/pwa/.commit
# (docs/DEPLOIEMENT-VPS.md), et le script vérifie que .commit correspond au SHA demandé.
# S'arrête à la première erreur. Une migration en attente arrête le déploiement (décision humaine).
# Codes de sortie : 1 erreur, 2 migration(s) en attente, 3 vérification finale échouée (conteneur
# arrêté, image exécutée ou code différents de la version demandée, worker ≠ backend).
# Toutes les commandes Docker visent le seul projet Compose de ce dossier : aucun autre conteneur
# du VPS n'est arrêté, recréé ou redémarré.
set -euo pipefail
cd "$(dirname "$0")"
ENV_NAME=$(basename "$PWD"); ENV_NAME=${ENV_NAME#medclick-}

VERIFY_ONLY=
MIGRATE_ONLY=
case "${1:-}" in
  --verify) VERIFY_ONLY=1; shift ;;
  --migrate) MIGRATE_ONLY=1; shift ;;
esac
BACKEND_SHA=${1:?SHA backend requis}
FRONTEND_SHA=${2:?SHA frontend requis}
BACKEND_REPO=https://github.com/Sftaita/medclick-backend.git
export BACKEND_TAG="${BACKEND_SHA:0:7}" FRONTEND_TAG="${FRONTEND_SHA:0:7}"

checkout() { # <dépôt> <dossier> <sha>
  [ -d "$2/.git" ] || git clone --quiet "$1" "$2"
  git -C "$2" fetch --quiet origin
  git -C "$2" -c advice.detachedHead=false checkout --quiet --force "$3"
  git -C "$2" clean -fdq
  echo "$2 @ $(git -C "$2" rev-parse HEAD)"
}

# Services du Compose (lus après la copie du fichier). Ne jamais écrire
# `docker compose config --services | grep -q …` avec pipefail : grep -q ferme le tube dès la première
# correspondance ; si Compose écrit encore (l'ordre des services varie d'un appel à l'autre), il reçoit
# SIGPIPE (141) et le test devient faux alors que le service existe (worker non recréé).
SERVICES=
load_services() { SERVICES=$(docker compose config --services); }
has_service() { grep -qx -- "$1" <<< "$SERVICES"; }

# Commande du NOUVEAU code dans un conteneur temporaire (image de BACKEND_TAG, supprimé à la fin) :
# - --no-deps : aucun autre service démarré ou recréé ;
# - traefik.enable=false : le conteneur reprend sinon les étiquettes du service backend et Traefik
#   lui enverrait une part du trafic de l'API (il ne sert pas de HTTP : erreurs 502) ;
# - cache Symfony propre au conteneur (non partagé avec le backend en service).
backend_once() {
  docker compose run --rm --no-deps -T -l traefik.enable=false -e APP_ENV=prod backend "$@"
}

# Nombre de migrations du code de l'image BACKEND_TAG non encore appliquées à la base.
pending_migrations() {
  backend_once php bin/console doctrine:migrations:status --no-interaction \
    | awk -F'|' '$3 ~ /New/ {gsub(/ /,"",$4); print $4}'
}

# Identifiant de l'image réellement exécutée par le conteneur d'un service (vide s'il n'existe pas).
running_image_id() {
  local cid; cid=$(docker compose ps -q "$1")
  if [ -n "$cid" ]; then docker inspect --format '{{.Image}}' "$cid"; fi
}

# ---------------------------------------------------------------------------------------------------
# Vérification finale : conteneurs démarrés et sains, image RÉELLEMENT exécutée (identifiant de
# l'image du conteneur, pas seulement l'étiquette du Compose) égale à l'image de l'étiquette demandée,
# backend et worker sur la même image, et code des conteneurs identique au commit demandé (empreinte
# de src/, migrations/, templates/ et composer.lock, calculée de la même façon des deux côtés).
# ---------------------------------------------------------------------------------------------------
CODE_FILES='find src migrations templates -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum; sha256sum composer.lock'
FAILED=0
fail() { echo "ÉCHEC : $*"; FAILED=1; }

check_service() { # <service> <étiquette attendue> [code]
  local service=$1 tag=$2 cid ref running health image_id expected_id code
  cid=$(docker compose ps -q "$service")
  if [ -z "$cid" ]; then fail "$service : aucun conteneur."; return; fi
  read -r ref running health image_id <<< "$(docker inspect --format '{{.Config.Image}} {{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{else}}sans-healthcheck{{end}} {{.Image}}' "$cid")"
  expected_id=$(docker image inspect --format '{{.Id}}' "${ref%:*}:$tag" 2>/dev/null || true)
  [ "$running" = "true" ] || fail "$service : conteneur arrêté."
  [[ "$health" == healthy || "$health" == sans-healthcheck ]] || fail "$service : état de santé « $health »."
  [ "${ref##*:}" = "$tag" ] || fail "$service : étiquette $ref au lieu de :$tag."
  if [ -z "$expected_id" ] || [ "$image_id" != "$expected_id" ]; then
    fail "$service : image exécutée ${image_id:7:12} différente de ${ref%:*}:$tag (${expected_id:7:12})."
  fi
  if [ "${3:-}" = code ]; then
    code=$(docker compose exec -T "$service" sh -c "cd /app && { $CODE_FILES; } | sha256sum | cut -c1-64" || true)
    [ "$code" = "$EXPECTED_CODE" ] || fail "$service : code du conteneur différent du commit $BACKEND_TAG."
  fi
  printf '  %-9s %-34s image %-12s %s\n' "$service" "$ref" "${image_id:7:12}" "$health"
}

verify_deployment() {
  local checked_out
  checked_out=$(git -C src/backend rev-parse HEAD)
  [[ "$checked_out" == "$BACKEND_SHA"* ]] || fail "src/backend est à $checked_out, pas à $BACKEND_SHA."
  [ "$(cat src/pwa/.commit 2>/dev/null)" = "$FRONTEND_SHA" ] || fail "src/pwa/.commit ne correspond pas à $FRONTEND_SHA."
  EXPECTED_CODE=$(cd src/backend && sh -c "$CODE_FILES" | sha256sum | cut -c1-64)

  echo "Versions déployées ($ENV_NAME) — backend demandé $BACKEND_SHA, frontend $FRONTEND_SHA :"
  check_service backend "$BACKEND_TAG" code
  if has_service worker; then
    check_service worker "$BACKEND_TAG" code
    [ "$(running_image_id worker)" = "$(running_image_id backend)" ] || fail "worker et backend n'exécutent pas la même image."
  fi
  check_service frontend "$FRONTEND_TAG"
  # Schéma : aucune migration du code en service ne doit rester en attente.
  local pending
  pending=$(docker compose exec -T backend php bin/console doctrine:migrations:status --no-interaction \
    | awk -F'|' '$3 ~ /New/ {gsub(/ /,"",$4); print $4}' || true)
  if [ "${pending:-?}" = "0" ]; then
    echo "  schéma    aucune migration en attente"
  else
    fail "migration(s) en attente pour le code en service : ${pending:-inconnu}."
  fi
  if [ "$FAILED" != 0 ]; then
    echo "VÉRIFICATION FINALE ÉCHOUÉE : déploiement incomplet (voir ci-dessus)."
    exit 3
  fi
  echo "Vérification finale : OK (backend et worker sur la même image, code = commit $BACKEND_TAG)."
}

[ -f .env ] || { echo ".env absent (modèle : src/backend/deploy/vps/.env.$ENV_NAME.example)"; exit 1; }
[ "$(stat -c %a .env)" = "600" ] || { echo ".env doit être en 600"; exit 1; }

if [ -n "$VERIFY_ONLY" ]; then
  load_services
  verify_deployment
  exit 0
fi

mkdir -p src
checkout "$BACKEND_REPO" src/backend "$BACKEND_SHA"
[ "$(cat src/pwa/.commit 2>/dev/null)" = "$FRONTEND_SHA" ] || { echo "src/pwa/.commit ne correspond pas à $FRONTEND_SHA"; exit 1; }
echo "src/pwa @ $FRONTEND_SHA (archive)"
[ -f "src/backend/deploy/vps/docker-compose.$ENV_NAME.yml" ] || { echo "environnement inconnu : $ENV_NAME"; exit 1; }
cp "src/backend/deploy/vps/docker-compose.$ENV_NAME.yml" docker-compose.yml
load_services

# Image du frontend en service : ses fichiers assets/ sont repris dans la nouvelle (onglets ouverts
# sur la version précédente). Premier déploiement : image nginx nue, rien à reprendre.
PREVIOUS_FRONTEND_IMAGE=$(docker inspect --format '{{.Config.Image}}' "medclick-$ENV_NAME-frontend" 2>/dev/null || echo nginx:1.29-alpine)
export PREVIOUS_FRONTEND_IMAGE
echo "assets précédents repris de : $PREVIOUS_FRONTEND_IMAGE"
if [ -n "$MIGRATE_ONLY" ]; then
  docker compose build backend
else
  docker compose build
fi
# Base démarrée (déjà en service en général). Mailpit n'existe qu'en préproduction (capture des e-mails).
docker compose up -d --wait db $(has_service mailpit && echo mailpit || true)

# ---------------------------------------------------------------------------------------------------
# Migrations seules (--migrate) : sauvegarde vérifiée, puis migrations exécutées par le nouveau code
# dans un conteneur temporaire. Aucun service applicatif n'est remplacé ni redémarré, et .env garde
# les étiquettes de la version en service. Les migrations doivent être additives (compatibles avec
# la version en service) : c'est la condition de ce mode, à vérifier dans la revue de la migration.
# ---------------------------------------------------------------------------------------------------
if [ -n "$MIGRATE_ONLY" ]; then
  PENDING=$(pending_migrations)
  echo "migration(s) en attente pour $BACKEND_TAG : ${PENDING:-?}"
  if [ "${PENDING:-}" = "0" ]; then echo "Rien à migrer."; exit 0; fi
  [ -n "${PENDING:-}" ] || { echo "état des migrations illisible : arrêt."; exit 1; }
  # Migrations qui vont être exécutées (seules les lignes « not migrated »).
  backend_once php bin/console doctrine:migrations:list --no-interaction | grep "not migrated" || true
  mkdir -p backups
  DUMP="backups/avant-migration-$BACKEND_TAG-$(date -u +%Y%m%d-%H%M%S).sql.gz"
  ( umask 077
    docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump -u"$MARIADB_USER" --single-transaction --no-tablespaces --routines --triggers "$MARIADB_DATABASE"' | gzip > "$DUMP" )
  gzip -t "$DUMP" && zcat "$DUMP" | tail -n 1 | grep -q "Dump completed" || { echo "sauvegarde invalide : $DUMP — arrêt sans migration."; exit 1; }
  sha256sum "$DUMP" > "$DUMP.sha256"
  echo "sauvegarde : $DUMP ($(stat -c %s "$DUMP") octets, gzip et fin de dump vérifiés)"
  backend_once php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
  PENDING=$(pending_migrations)
  [ "$PENDING" = "0" ] || { echo "ÉCHEC : ${PENDING:-?} migration(s) encore en attente après exécution."; exit 1; }
  echo "Migrations appliquées par le code $BACKEND_TAG. Services en service inchangés :"
  docker compose ps
  echo "Contrôler la version en service, puis : ./deploy.sh $BACKEND_SHA $FRONTEND_SHA"
  exit 0
fi

# ---------------------------------------------------------------------------------------------------
# VERROU DE SCHÉMA : le nouveau code ne remplace la version en service que si la base est déjà à son
# niveau de migration. Contrôle fait dans un conteneur temporaire, AVANT tout remplacement de service
# et avant l'enregistrement des étiquettes dans .env (un « docker compose up » manuel lance alors
# toujours la version précédente).
# ---------------------------------------------------------------------------------------------------
PENDING=$(pending_migrations)
if [ "${PENDING:-}" != "0" ]; then
  echo "ARRÊT (aucun service remplacé) : ${PENDING:-état illisible} migration(s) en attente pour $BACKEND_TAG."
  echo "Sauvegarder, puis : ./deploy.sh --migrate $BACKEND_SHA $FRONTEND_SHA ; contrôler la version en service ; relancer ce déploiement."
  exit 2
fi
echo "verrou de schéma : aucune migration en attente pour $BACKEND_TAG"

# Étiquettes de ce déploiement enregistrées dans .env : une commande docker compose lancée à la main
# dans ce dossier utilise ces images, jamais une plus ancienne (le Compose refuse une étiquette absente).
for TAG_VAR in BACKEND_TAG FRONTEND_TAG; do
  sed -i "/^${TAG_VAR}=/d" .env && echo "${TAG_VAR}=${!TAG_VAR}" >> .env
done
[ "$(stat -c %a .env)" = "600" ] || { echo ".env doit rester en 600"; exit 1; }

# Clés JWT de CET environnement (phrase de passe du .env), générées une seule fois dans le volume.
backend_once sh -c \
  'php bin/console lexik:jwt:generate-keypair --skip-if-exists && chown www-data:www-data config/jwt/*.pem && chmod 600 config/jwt/private.pem && chmod 644 config/jwt/public.pem'

docker compose up -d --wait backend
docker compose exec -T backend php bin/console cache:clear --no-warmup
docker compose exec -T backend php bin/console cache:warmup
docker compose exec -T backend chown -R www-data:www-data var

# Contrôle de sûreté (le verrou de schéma l'a déjà garanti) : toujours aucune migration en attente.
PENDING=$(docker compose exec -T backend php bin/console doctrine:migrations:status --no-interaction | awk -F'|' '$3 ~ /New/ {gsub(/ /,"",$4); print $4}')
if [ "${PENDING:-?}" != "0" ]; then
  echo "ARRÊT : ${PENDING:-état illisible} migration(s) en attente après la mise en service — à examiner."
  exit 2
fi

# Worker des exports Excel, après les migrations (il lit la file messenger_messages). Même image que le
# backend : Compose le recrée quand l'image change (--no-deps : aucun autre service n'est touché).
# Arrêt propre : la génération en cours se termine (stop_grace_period). Si le worker n'exécute pas
# encore l'image du backend, une recréation forcée est tentée ; la vérification finale échoue sinon.
if has_service worker; then
  docker compose up -d --no-deps --wait --wait-timeout 300 worker
  if [ "$(running_image_id worker)" != "$(running_image_id backend)" ]; then
    echo "worker sur une autre image que le backend : recréation forcée."
    docker compose up -d --no-deps --force-recreate --wait --wait-timeout 300 worker
  fi
fi

# legacy-apex-sw n'existe qu'en production (SW de remplacement de l'ancien front sur l'apex) :
# recréé s'il a disparu, laissé tel quel sinon.
docker compose up -d --wait frontend $(has_service legacy-apex-sw && echo legacy-apex-sw || true)
docker compose ps

# Gardien des exports (cron de l'hôte, indépendant du worker, docs/DEPLOIEMENT-VPS.md) : signalé s'il manque.
CRONTAB=$(crontab -l 2>/dev/null || true)
grep -q "apps/medclick-$ENV_NAME .*app:exports:monitor --watchdog" <<< "$CRONTAB" \
  || echo "ATTENTION : gardien des exports absent de la crontab (docs/DEPLOIEMENT-VPS.md, « Worker des exports Excel »)."

verify_deployment
