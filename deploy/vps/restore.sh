#!/usr/bin/env bash
# Restauration d'une sauvegarde MedClick chiffrée (backup.sh).
# Usage :
#   restore.sh test <fichier.sql.gz.gpg>
#       → base MariaDB 11.8 JETABLE (conteneur sans réseau, supprimé à la fin) : contrôle que la
#         sauvegarde se restaure et affiche les comptages. Ne touche à aucune base existante.
#   restore.sh into <dossier du projet compose> <fichier.sql.gz.gpg>
#       → restaure DANS la base du projet (écrase son contenu). Réservé à une décision explicite.
set -euo pipefail
export PATH="$HOME/bin:$PATH"
PASSFILE="$HOME/.medclick-backup-passphrase"
MODE=${1:?test|into}

decrypt() { gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$PASSFILE" -d "$1" | gunzip; }
verify() { # le .sha256 voisin, s'il existe
  local f=$1; [ -f "$f.sha256" ] && (cd "$(dirname "$f")" && sha256sum -c "$(basename "$f").sha256")
}

COUNTS="SELECT COUNT(*) AS tables FROM information_schema.tables WHERE table_schema = DATABASE();
SELECT 'surgeries', COUNT(*) FROM surgeries UNION ALL SELECT 'user', COUNT(*) FROM user
UNION ALL SELECT 'years', COUNT(*) FROM years UNION ALL SELECT 'consultations', COUNT(*) FROM consultations
UNION ALL SELECT 'gardes', COUNT(*) FROM gardes UNION ALL SELECT 'formations', COUNT(*) FROM formations;"

if [ "$MODE" = test ]; then
  FILE=${2:?fichier requis}; verify "$FILE"
  NAME="medclick-restore-test-$$"; PW=$(openssl rand -hex 16)
  trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
  docker run -d --name "$NAME" --network none -e MARIADB_ROOT_PASSWORD="$PW" -e MARIADB_DATABASE=restore_test mariadb:11.8 >/dev/null
  until docker exec "$NAME" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do sleep 2; done
  decrypt "$FILE" | docker exec -i "$NAME" mariadb -uroot -p"$PW" restore_test
  echo "$COUNTS" | docker exec -i "$NAME" mariadb -uroot -p"$PW" restore_test
  echo "Restauration de test réussie (base jetable supprimée)."
elif [ "$MODE" = into ]; then
  PROJECT=${2:?dossier du projet requis}; FILE=${3:?fichier requis}; verify "$FILE"
  read -r -p "Écraser la base de $PROJECT avec $FILE ? Taper OUI : " ok; [ "$ok" = OUI ] || exit 1
  decrypt "$FILE" | docker compose --project-directory "$PROJECT" exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
  echo "$COUNTS" | docker compose --project-directory "$PROJECT" exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
else
  echo "mode inconnu : $MODE"; exit 1
fi
