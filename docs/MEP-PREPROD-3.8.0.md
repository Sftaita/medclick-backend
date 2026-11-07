# Livraison en préproduction — MedClick 3.8.0 (recherche INAMI + publicités par emplacement)

Préproduction uniquement (`https://staging.medclick.be`, `/opt/stack/apps/medclick-staging`).
**Aucune action sur la production** : elle exige une décision explicite (ADR-207) et un plan propre.
Procédure générale : `docs/DEPLOIEMENT-VPS.md` (« Mise à jour avec migration »).
Documentation fonctionnelle : `docs/RECHERCHE-INAMI.md`, `docs/PUBLICITES-PARTENAIRES.md`.

## Versions livrées

| Dépôt | Branche | SHA (`<B>`, `<F>`) |
|---|---|---|
| backend (public) | `integration/recherche-inami-refonte` | à relever au moment de la livraison (`git rev-parse`) |
| frontend (privé) | `integration/recherche-inami-refonte` | idem ; version affichée **3.8.0** |

État de départ constaté (06/11/2026, lecture seule) : préproduction backend `40e0d74`, frontend
`338277d` ; production backend `6ad8004`, frontend `21b0d96`.

## Isolation de la préproduction (constatée le 06/11/2026, lecture seule)

| Point | Constat |
|---|---|
| Base | conteneur `medclick-staging-db`, base `medclick_staging`, utilisateur `medclick_staging`, volume `medclick-staging_medclick_staging_db` ; réseau `medclick-staging-internal` sans accès à `medclick-prod-internal` |
| Secrets | `.env` propre (chmod 600) ; clés JWT distinctes de la production (empreintes différentes), volume `…_staging_jwt` |
| Fichiers | volumes `exports`, `jwt`, `log` propres à `medclick-staging` |
| E-mails | `MAILER_DSN=smtp://mailpit:1025` : tous les e-mails capturés par Mailpit (`medclick-staging-mailpit`), aucun envoi réel |
| URL | `PUBLIC_URL`, `PUBLIC_FRONTEND_URL`, `PUBLIC_API_URL` = `https://staging.medclick.be` |
| Accès | liste d'IP Traefik (`medclick-staging.yml`) |
| Migrations exécutées | `Version20261104090000` à `Version20261107090000` (dont `Version20261105090000` **de master**, partage de nomenclature) ; tables `ad_placement_setting` et `inami_search_stat` **absentes** |

À refaire juste avant la livraison (les mêmes commandes, en lecture seule) :
```bash
cd /opt/stack/apps/medclick-staging
docker compose -f src/backend/deploy/vps/docker-compose.staging.yml --project-directory . config | grep -E "MAILER_DSN|PUBLIC_URL"
docker exec medclick-staging-backend php bin/console doctrine:migrations:list | grep -E "Version2026110[5-9]"
```

## Migrations attendues (2, additives, compatibles avec la version en service)

1. `Version20261108090000` : table `inami_search_stat` (compteurs quotidiens, aucune donnée personnelle).
2. `Version20261109090000` : emplacements publicitaires de la refonte (table `ad_placement_setting`,
   colonnes facultatives `marketing.partner_id`, `pending_changes`, contacts des entreprises ;
   rattachement des annonceurs à une entreprise). **Écrite `Version20261105090000`, renommée** :
   ce nom est déjà enregistré en préproduction et en production pour la migration du partage de
   nomenclature ; sous l'ancien nom, elle n'aurait jamais été exécutée.

## Procédure

0. **Prérequis** : branches poussées (backend public, après contrôle d'absence de secrets) ; CI verte ;
   `./deploy.sh --verify 40e0d74… 338277d…` sur la préproduction : OK ; services sains.
1. **Sauvegarde** : `./backup.sh /opt/stack/apps/medclick-staging staging` (chiffrée, contrôlée, copiée
   hors VPS) ; noter le fichier. Copie des sources du front en service : `cp -a src/pwa src/pwa.338277d`.
2. **Front** (poste) : `git -C <pwa-medclick> archive <F> frontend | ssh surgicalhub-prod "tar -x -C /opt/stack/apps/medclick-staging/src/pwa"`
   puis `ssh surgicalhub-prod "echo <F> > /opt/stack/apps/medclick-staging/src/pwa/.commit"`.
3. `./deploy.sh <B> <F>` : build, puis arrêt attendu (code 2, « 2 migration(s) en attente ») avant tout
   remplacement de service.
4. `./deploy.sh --migrate <B> <F>` : sauvegarde `backups/avant-migration-…`, puis les 2 migrations.
5. Contrôle de la version en service sur le schéma enrichi (connexion, tableau de bord, un export Excel,
   `error_log` sans erreur).
6. `./deploy.sh <B> <F>` puis `./deploy.sh --verify <B> <F>` (code 0).
7. Rattachement des publicités existantes à leur entreprise (refonte) :
   `docker exec medclick-staging-backend php bin/console app:attach-campaigns-to-company` (simulation),
   puis avec `--apply` si le résultat est conforme.

## Tests de fumée (après l'étape 6)

| Contrôle | Attendu |
|---|---|
| `GET https://staging.medclick.be/api/public/inami/specialities` (sans jeton) | 200, 9 spécialités |
| `GET …/api/public/inami/codes?speciality=all` | 200, `total` = codes actifs (1 347 au 06/11/2026) |
| `GET …/api/public/inami/codes?speciality=ortho&q=genou` | 200, résultats ; en-têtes `Cache-Control: max-age=60, public`, `ETag` |
| `GET …/api/public/inami/codes?speciality=ortho&limit=1` | 400 |
| `GET …/api/nomenclature/ortho`, `…/api/surgeries`, `…/api/admin/inami-stats` (sans jeton) | 401 |
| `GET …/api/marketing/slots` | 200, clé `inami_search` |
| `doctrine:migrations:status` | aucune migration en attente |
| Navigateur : `/login` → « Trouver un code INAMI » → « Oui » → recherche, copie, smartphone | conforme à `docs/RECHERCHE-INAMI.md` |
| Administration › Publicités & partenaires | neuf emplacements, « Recherche INAMI » sous « Pages publiques », aucun conflit |
| Mailpit | aucun e-mail inattendu |

## Retour arrière

- **Sans écriture dans la nouvelle administration** : `docker exec medclick-staging-backend php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261107090000' --no-interaction`
  (descend `Version20261109090000` puis `Version20261108090000`), puis `./deploy.sh 40e0d74… 338277d…`
  après avoir rétabli `src/pwa` (`rm -rf src/pwa && cp -a src/pwa.338277d src/pwa`).
- **Après des écritures ou en cas d'échec de migration** : `./restore.sh into /opt/stack/apps/medclick-staging <sauvegarde de l'étape 1>`,
  puis `./deploy.sh 40e0d74… 338277d…` (sources du front rétablies comme ci-dessus).
- Les entreprises créées par `Version20261109090000` pour les annonceurs restent après la descente
  (fiches sans diffusion, ignorées par la version précédente).
