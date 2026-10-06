#!/usr/bin/env bash
# Rétention des sauvegardes MedClick : 7 quotidiennes, 4 hebdomadaires, 6 mensuelles.
# Lit sur l'entrée standard des noms « medclick-<env>-AAAAMMJJTHHMMSSZ.sql.gz.gpg » (un par ligne,
# autres lignes ignorées) et écrit sur la sortie standard ceux à SUPPRIMER. Ne supprime rien.
# Garde la plus récente de chaque jour (7 derniers jours ayant une sauvegarde), de chaque semaine
# ISO (4 dernières) et de chaque mois (6 derniers) ; la plus récente de toutes est toujours gardée.
# Variables : KEEP_DAILY (7), KEEP_WEEKLY (4), KEEP_MONTHLY (6).
set -euo pipefail

KEEP_DAILY=${KEEP_DAILY:-7}
KEEP_WEEKLY=${KEEP_WEEKLY:-4}
KEEP_MONTHLY=${KEEP_MONTHLY:-6}

mapfile -t names < <(grep -E '^medclick-[a-z0-9]+-[0-9]{8}T[0-9]{6}Z\.sql\.gz\.gpg$' | sort -t- -k3 -r)
declare -A keep=() day=() week=() month=()

for name in "${names[@]}"; do
  stamp=${name##*-}; stamp=${stamp%%Z.sql.gz.gpg}
  d=${stamp:0:8}
  w=$(date -u -d "$d" +%G-%V)
  m=${stamp:0:6}
  if [ -z "${day[$d]:-}" ] && [ "${#day[@]}" -lt "$KEEP_DAILY" ]; then day[$d]=1; keep[$name]=1; fi
  if [ -z "${week[$w]:-}" ] && [ "${#week[@]}" -lt "$KEEP_WEEKLY" ]; then week[$w]=1; keep[$name]=1; fi
  if [ -z "${month[$m]:-}" ] && [ "${#month[@]}" -lt "$KEEP_MONTHLY" ]; then month[$m]=1; keep[$name]=1; fi
done

[ "${#names[@]}" -gt 0 ] && keep[${names[0]}]=1
for name in "${names[@]}"; do
  [ -n "${keep[$name]:-}" ] || echo "$name"
done
