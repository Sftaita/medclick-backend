# Frontend MedClick unifié (React/Vite, dépôt pwa-medclick, dossier frontend/).
# Construit depuis frontend/ : docker build -f <ce fichier> --build-arg VITE_API_URL=... <frontend>
# Le runtime ne contient que les fichiers statiques et nginx (pas de Node).

FROM node:24.21.0-alpine AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# Vite intègre import.meta.env.* au build : l'URL de l'API est un ARG de build, pas une variable
# d'exécution. Ex. https://staging.medclick.be/api/ (même origine, routée vers le backend).
ARG VITE_API_URL
RUN test -n "$VITE_API_URL" && VITE_API_URL="$VITE_API_URL" npm run build

FROM nginx:1.29-alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY --from=deploy frontend-nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
