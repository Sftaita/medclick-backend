#!/usr/bin/env bash
# Sauvegarde MedClick (VPS) : dump MariaDB → gzip → chiffrement gpg (AES-256) → SHA-256
# → copie hors VPS (rclone, remote « gdrive » existant) → rotation locale et distante.
# Usage : backup.sh <dossier du projet compose> <nom d'environnement>
#   ex. backup.sh /opt/stack/apps/medclick-staging staging
# Prérequis : ~/.medclick-backup-passphrase (chmod 600, conservée AUSSI hors du VPS),
#             rclone dans ~/bin avec le remote gdrive.
set -euo pipefail
export PATH="$HOME/bin:$PATH"

PROJECT=${1:?dossier du projet compose requis}
ENV_NAME=${2:?nom de l environnement requis}
PASSFILE="$HOME/.medclick-backup-passphrase"
DEST="$HOME/backups/medclick-$ENV_NAME"
REMOTE="gdrive:INFORMATIQUE/Base de donnée/medclick-$ENV_NAME"
KEEP_LOCAL_DAYS=14
KEEP_REMOTE_DAYS=30
LOG="$HOME/backups/backup.log"
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [medclick-$ENV_NAME] $*" | tee -a "$LOG"; }

[ -f "$PASSFILE" ] && [ "$(stat -c %a "$PASSFILE")" = "600" ] || { log "ERREUR : $PASSFILE absent ou pas en 600"; exit 1; }
umask 077
mkdir -p "$DEST"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
OUT="$DEST/medclick-$ENV_NAME-$STAMP.sql.gz.gpg"

log "dump"
# Identifiants lus dans le conteneur (variables MARIADB_*), jamais en clair ici.
docker compose --project-directory "$PROJECT" exec -T db sh -c \
  'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers --no-tablespaces "$MARIADB_DATABASE"' \
  | gzip \
  | gpg --batch --yes --pinentry-mode loopback --passphrase-file "$PASSFILE" --symmetric --cipher-algo AES256 -o "$OUT"

# Contrôle immédiat : déchiffrable, gzip valide, dump complet.
gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$PASSFILE" -d "$OUT" | gunzip | tail -1 | grep -q "Dump completed" \
  || { log "ERREUR : sauvegarde illisible ou incomplète ($OUT)"; rm -f "$OUT"; exit 1; }
(cd "$DEST" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256")
log "OK $(basename "$OUT") ($(du -h "$OUT" | cut -f1))"

log "copie hors VPS → $REMOTE"
rclone copy "$DEST" "$REMOTE" --include "*.gpg" --include "*.sha256" --log-file "$LOG" --log-level NOTICE
rclone check "$DEST" "$REMOTE" --one-way --include "$(basename "$OUT")" --log-file "$LOG" --log-level NOTICE \
  || { log "ERREUR : copie distante non vérifiée"; exit 1; }

# Rotation : parenthèses explicites (les deux motifs ET l'âge).
find "$DEST" -type f \( -name "*.gpg" -o -name "*.sha256" \) -mtime +"$KEEP_LOCAL_DAYS" -print -delete | sed "s#^#[rotation locale] #" >> "$LOG"
rclone delete "$REMOTE" --min-age "${KEEP_REMOTE_DAYS}d" --log-file "$LOG" --log-level NOTICE
log "terminé"
