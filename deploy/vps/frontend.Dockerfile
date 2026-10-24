# Frontend MedClick unifié (React/Vite, dépôt pwa-medclick, dossier frontend/).
# Construit depuis frontend/ : docker build -f <ce fichier> --build-arg VITE_API_URL=... <frontend>
# Le runtime ne contient que les fichiers statiques et nginx (pas de Node).
#
# PREVIOUS_IMAGE (deploy.sh : image du frontend en service) : ses fichiers assets/ sont conservés
# dans la nouvelle image. Un onglet resté ouvert sur la version précédente, ou une PWA servie par
# l'ancien service worker, charge encore ses pages à la demande (/assets/<page>-<empreinte>.js) :
# sans ces fichiers, il afficherait « Une erreur est survenue » jusqu'au rechargement. Seule la
# version précédente est gardée (liste .release-assets), pas tout l'historique.
ARG PREVIOUS_IMAGE=nginx:1.29-alpine

FROM ${PREVIOUS_IMAGE} AS previous
# Image sans .release-assets (avant ce mécanisme) : tout son dossier assets/ est repris.
RUN mkdir -p /previous && cd /usr/share/nginx/html \
    && if [ -f .release-assets ]; then tar -cf - -T .release-assets | tar -xf - -C /previous; \
       elif [ -d assets ]; then cp -a assets /previous/; fi

FROM node:24.21.0-alpine AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# Vite intègre import.meta.env.* au build : l'URL de l'API est un ARG de build, pas une variable
# d'exécution. Ex. https://staging.medclick.be/api/ (même origine, routée vers le backend).
ARG VITE_API_URL
RUN test -n "$VITE_API_URL" && VITE_API_URL="$VITE_API_URL" npm run build \
    && (cd dist && find assets -type f | sort) > release-assets

FROM nginx:1.29-alpine

COPY --from=previous /previous/ /usr/share/nginx/html/
COPY --from=build /app/dist /usr/share/nginx/html
# Fichiers propres à cette version : seuls ceux-là seront conservés au déploiement suivant.
COPY --from=build /app/release-assets /usr/share/nginx/html/.release-assets
COPY --from=deploy frontend-nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
