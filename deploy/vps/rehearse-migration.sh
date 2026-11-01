#!/usr/bin/env bash
# Répétition de la mise en production « Publicités & partenaires » sur une COPIE de la production
# (sauvegarde chiffrée de backup.sh), dans des conteneurs jetables : rien n'est lu ni écrit dans
# une base existante. Voir docs/PUBLICITES-PARTENAIRES.md, section 9.
#
# Usage (VPS, utilisateur deploy) :
#   rehearse-migration.sh <sauvegarde.sql.gz.gpg> <image backend actuelle> <image backend nouvelle> <dossier du rapport>
#
# Étapes, chronométrées :
#   1. restauration de la sauvegarde dans une MariaDB 11.8 jetable (réseau interne, sans port) ;
#   2. empreinte de toutes les tables et affichage public (version actuelle) ;
#   3. sauvegarde « avant migration » de la copie (fichier de travail, supprimé à la fin) ;
#   4. migration avec la nouvelle version, contrôles (publicités sans entreprise, doublons) ;
#   5. affichage public (nouvelle version) comparé à l'étape 2 ;
#   6. rattachement « BOSI Knee Academy » : simulation, application puis retour arrière ;
#   7. retour arrière A : descente de la migration, affichage public (version actuelle) identique ;
#   8. retour arrière B : restauration de la sauvegarde « avant migration » dans une base neuve,
#      empreinte identique à l'étape 2.
# Le rapport ne contient que des comptages, des empreintes et des données publiques (publicités,
# entreprises) : aucune donnée personnelle.
set -euo pipefail
BACKUP=${1:?sauvegarde .sql.gz.gpg requise}; OLD=${2:?image actuelle requise}; NEW=${3:?image nouvelle requise}; REPORT=${4:?dossier du rapport requis}
PASSFILE="$HOME/.medclick-backup-passphrase"
NET=medclick-rehearsal; DB=medclick-rehearsal-db; DB2=medclick-rehearsal-db2; APP=medclick-rehearsal-app
PW=$(openssl rand -hex 16); SECRET=$(openssl rand -hex 32)
containers() { docker rm -f $DB $DB2 $APP >/dev/null 2>&1 || true; docker network rm $NET >/dev/null 2>&1 || true; }
containers
WORK=$(mktemp -d); chmod 700 "$WORK"
trap 'containers; rm -rf "$WORK"' EXIT
mkdir -p "$REPORT"
log() { echo "[$(date +%H:%M:%S)] $*" | tee -a "$REPORT/journal.txt"; }
t0=$SECONDS; step() { log "$1 (t+$((SECONDS - t0)) s)"; }

(cd "$(dirname "$BACKUP")" && sha256sum -c "$(basename "$BACKUP").sha256") >/dev/null
docker network create --internal $NET >/dev/null
newdb() { # <nom>
  docker run -d --name "$1" --network $NET -e MARIADB_ROOT_PASSWORD="$PW" -e MARIADB_DATABASE=medclick mariadb:11.8 \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
  until docker exec "$1" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 2; done
}
sql() { docker exec -i "${2:-$DB}" mariadb -uroot -p"$PW" medclick ${1:-}; }
console() { # <image> <base> <commande…>
  local image=$1 db=$2; shift 2
  docker run --rm --network $NET -e APP_ENV=prod -e APP_DEBUG=0 -e APP_SECRET="$SECRET" -e JWT_PASSPHRASE=x -e JWT_SECRET_KEY=/tmp/absent.pem -e JWT_PUBLIC_KEY=/tmp/absent.pem -e MAILER_DSN=null://null \
    -e CORS_ALLOW_ORIGIN='^$' -e DATABASE_URL="mysql://root:$PW@$db:3306/medclick?serverVersion=11.8.9-MariaDB&charset=utf8mb4" "$image" "$@"
}
capture() { # <image> <fichier> : affichage public servi par cette version (routes publiques en lecture)
  docker run -d --name $APP --network $NET -e APP_ENV=prod -e APP_DEBUG=0 -e APP_SECRET="$SECRET" -e JWT_PASSPHRASE=x -e JWT_SECRET_KEY=/tmp/absent.pem -e JWT_PUBLIC_KEY=/tmp/absent.pem -e MAILER_DSN=null://null \
    -e CORS_ALLOW_ORIGIN='^$' -e DATABASE_URL="mysql://root:$PW@$DB:3306/medclick?serverVersion=11.8.9-MariaDB&charset=utf8mb4" "$1" >/dev/null
  local waited=0
  until [ "$(docker inspect --format '{{.State.Health.Status}}' $APP)" = healthy ]; do
    sleep 2; waited=$((waited + 2))
    [ $waited -lt 180 ] || { docker exec $APP sh -c 'tail -c 2000 var/log/prod-*.log' >&2 || true; log "   ÉCHEC : l'application $1 ne démarre pas sur la copie"; exit 1; }
  done
  { echo '{"slots":'; docker exec $APP curl -fsS localhost/api/marketing/slots; echo ',"placements":'; docker exec $APP curl -fsS localhost/api/partner/placements
    echo ',"interstitial_status":'; docker exec $APP curl -s -o /dev/null -w '%{http_code}' localhost/api/marketing/active; echo '}'; } > "$2"
  docker rm -f $APP >/dev/null
}
summary() { # résumé comparable : identifiants par emplacement, entreprises par emplacement partenaire
  docker run --rm -v "$1:/f.json:ro" --entrypoint php "$NEW" -r '
    $d = json_decode(file_get_contents("/f.json"), true);
    foreach ($d["slots"] as $k => $list) echo "publicités $k : ", implode(",", array_column($list, "id")) ?: "-", "\n";
    foreach ($d["placements"] as $k => $v) echo "entreprise $k : ", is_array($v) && isset($v[0]) ? implode(",", array_column($v, "name")) : ($v["name"] ?? "-"), "\n";
    echo "plein écran : HTTP ", $d["interstitial_status"], "\n";'
}
FP="$(cd "$(dirname "$0")" && pwd)/fingerprint.sql"

step "1. Restauration de la copie ($(basename "$BACKUP"))"
newdb $DB
gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$PASSFILE" -d "$BACKUP" | gunzip | sql
sql <<< "SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema = DATABASE(); SELECT 'marketing', COUNT(*) FROM marketing UNION ALL SELECT 'partner', COUNT(*) FROM partner UNION ALL SELECT 'partner_placement_assignment', COUNT(*) FROM partner_placement_assignment UNION ALL SELECT 'marketing_view', COUNT(*) FROM marketing_view UNION ALL SELECT 'partner_event', COUNT(*) FROM partner_event UNION ALL SELECT 'user', COUNT(*) FROM user;" | tee "$REPORT/1-comptages.txt"
console "$OLD" $DB php bin/console doctrine:migrations:status 2>/dev/null | grep -E "Current|Latest|New" | tee -a "$REPORT/1-comptages.txt"

step "2. Empreinte et affichage public (version actuelle)"
docker exec -i $DB mariadb -uroot -p"$PW" medclick < "$FP" > "$REPORT/2-empreinte-avant.tsv"
sql <<< "SELECT id, campaign_name, status, start_date, end_date, placements, sponsor_name FROM marketing ORDER BY id; SELECT id, name, status FROM partner ORDER BY id; SELECT table_name, table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('marketing', 'partner');" > "$REPORT/2-publicites-entreprises-avant.tsv"
capture "$OLD" "$REPORT/2-affichage-avant.json"; summary "$REPORT/2-affichage-avant.json" > "$REPORT/2-affichage-avant.txt"

step "3. Sauvegarde « avant migration » de la copie"
docker exec $DB sh -c "exec mariadb-dump -uroot -p$PW --single-transaction --routines --triggers --no-tablespaces medclick" | gzip > "$WORK/avant.sql.gz"
log "   $(du -h "$WORK/avant.sql.gz" | cut -f1) (fichier de travail, supprimé à la fin)"

MAXP=$(sql -N <<< "SELECT COALESCE(MAX(id), 0) FROM partner")
step "4. Migration (nouvelle version)"
s=$SECONDS; console "$NEW" $DB php bin/console doctrine:migrations:migrate -n 2>&1 | grep -E "OK|ERROR|error|Migrating" | tee "$REPORT/4-migration.txt"
log "   durée de la migration : $((SECONDS - s)) s"
console "$NEW" $DB php bin/console doctrine:schema:validate 2>&1 | grep -E "OK|ERROR|not in sync" | tee -a "$REPORT/4-migration.txt" || true
sql <<< "SELECT 'réglages', COUNT(*) FROM ad_placement_setting UNION ALL SELECT 'entreprises', COUNT(*) FROM partner UNION ALL SELECT 'publicités', COUNT(*) FROM marketing UNION ALL SELECT 'publicités rattachées', COUNT(partner_id) FROM marketing;
SELECT 'Publicités sans entreprise :' AS ''; SELECT id, campaign_name, status, placements FROM marketing WHERE partner_id IS NULL ORDER BY id;
SELECT 'Entreprises créées par la migration :' AS ''; SELECT id, name FROM partner WHERE id > $MAXP ORDER BY id;
SELECT 'Doublons de nom d entreprise :' AS ''; SELECT LOWER(TRIM(name)) COLLATE utf8mb4_unicode_ci AS nom, COUNT(*) FROM partner GROUP BY 1 HAVING COUNT(*) > 1;" | tee "$REPORT/4-controles.txt"
docker exec -i $DB mariadb -uroot -p"$PW" medclick < "$FP" > "$REPORT/4-empreinte-apres.tsv"

step "5. Affichage public (nouvelle version)"
capture "$NEW" "$REPORT/5-affichage-apres.json"; summary "$REPORT/5-affichage-apres.json" > "$REPORT/5-affichage-apres.txt"
if diff "$REPORT/2-affichage-avant.txt" "$REPORT/5-affichage-apres.txt" > "$REPORT/5-difference.txt"; then log "   affichage identique (mêmes publicités, mêmes entreprises, par emplacement)"; else log "   DIFFÉRENCE d'affichage : voir 5-difference.txt"; fi

step "6. Rattachement « BOSI Knee Academy » (simulation, application, retour arrière)"
KNEE=$(sql "-N" <<< "SELECT GROUP_CONCAT(CONCAT('--campaign=', id) SEPARATOR ' ') FROM marketing WHERE campaign_name LIKE '%Knee Academy%' AND partner_id IS NULL")
if [ -n "$KNEE" ] && [ "$KNEE" != NULL ]; then
  # shellcheck disable=SC2086
  console "$NEW" $DB php bin/console app:attach-campaigns-to-company $KNEE --company=BOSI --expect-name="Knee Academy" | tee "$REPORT/6-rattachement-simulation.txt"
  # shellcheck disable=SC2086
  console "$NEW" $DB php bin/console app:attach-campaigns-to-company $KNEE --company=BOSI --expect-name="Knee Academy" --apply | tail -3 | tee "$REPORT/6-rattachement.txt"
  capture "$NEW" "$REPORT/6-affichage-rattache.json"
  docker run --rm -v "$REPORT/6-affichage-rattache.json:/f.json:ro" --entrypoint php "$NEW" -r '$d = json_decode(file_get_contents("/f.json"), true); foreach (["dashboard_desktop","dashboard_mobile"] as $k) { foreach ($d["slots"][$k] as $c) echo "$k #", $c["id"], " : annonceur affiché = ", $c["sponsor"] ?? "-", "\n"; }' | tee -a "$REPORT/6-rattachement.txt"
  # shellcheck disable=SC2086
  console "$NEW" $DB php bin/console app:attach-campaigns-to-company $KNEE --company=BOSI --detach --apply | tail -3 | tee -a "$REPORT/6-rattachement.txt"
else
  log "   aucune publicité « Knee Academy » sans entreprise dans cette copie"
fi

step "7. Retour arrière A : descente de la migration"
s=$SECONDS; console "$NEW" $DB php bin/console doctrine:migrations:migrate prev -n 2>&1 | grep -E "OK|ERROR" | tee "$REPORT/7-descente.txt"
log "   durée : $((SECONDS - s)) s"
docker exec -i $DB mariadb -uroot -p"$PW" medclick < "$FP" > "$REPORT/7-empreinte-apres-descente.tsv"
capture "$OLD" "$REPORT/7-affichage-apres-descente.json"; summary "$REPORT/7-affichage-apres-descente.json" > "$REPORT/7-affichage-apres-descente.txt"
if diff "$REPORT/2-affichage-avant.txt" "$REPORT/7-affichage-apres-descente.txt" >/dev/null; then log "   affichage identique à l'étape 2 (version actuelle)"; else log "   DIFFÉRENCE après descente"; fi
# Tables identiques à l'étape 2 sauf partner (entreprises créées pour les annonceurs, conservées).
join -t $'\t' <(sort "$REPORT/2-empreinte-avant.tsv") <(sort "$REPORT/7-empreinte-apres-descente.tsv") -v1 > "$REPORT/7-tables-differentes.tsv" || true
log "   tables différentes de l'étape 2 : $(cut -f1 "$REPORT/7-tables-differentes.tsv" | grep -v '^t$' | tr '\n' ' ')"

step "8. Retour arrière B : restauration de la sauvegarde « avant migration »"
s=$SECONDS; newdb $DB2; gunzip -c "$WORK/avant.sql.gz" | sql "" $DB2
log "   durée de la restauration : $((SECONDS - s)) s"
docker exec -i $DB2 mariadb -uroot -p"$PW" medclick < "$FP" > "$REPORT/8-empreinte-restauree.tsv"
if diff "$REPORT/2-empreinte-avant.tsv" "$REPORT/8-empreinte-restauree.tsv" >/dev/null; then log "   empreinte identique à l'étape 2 : toutes les tables restaurées à l'identique"; else log "   DIFFÉRENCE après restauration : voir 8-empreinte-restauree.tsv"; fi

step "Terminé ; conteneurs, réseau et fichiers de travail supprimés"
