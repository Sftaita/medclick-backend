# Plan de mise en production — MedClick 3.8.0 (recherche INAMI + refonte publicitaire)

À exécuter seulement après décision explicite de mise en production (ADR-207) et résolution des
points juridiques de `docs/MEP-3.8.0-COOKIES.md` (§ 5). Dossier : `/opt/stack/apps/medclick-prod`.
Commandes `deploy.sh` lancées avec `</dev/null`. Procédure générale : `docs/DEPLOIEMENT-VPS.md`.

## Versions

| | Backend (public) | PWA (privé) |
|---|---|---|
| En production (départ) | `6ad8004eab83974a3a557f1622e2982ee437ea43` | `21b0d9639e79bc8b1b18203a1f30c9b93d8a4d71` |
| Release | branche `release/3.8.0`, étiquette `v3.8.0` (`<B>`) | branche `release/3.8.0`, étiquette `v3.8.0` (`<F>`), version affichée 3.8.0 |

## Contenu, et ce qui n'en fait pas partie (ADR-215)

La release backend part de `6ad8004` (production) et reprend les fusions de l'intégration
(`integration/recherche-inami-refonte`, PR #17) : refonte publicitaire (`feature/refonte-publicites-partenaires`),
recherche INAMI (`feature/recherche-inami`) et le format 1200 × 300 de l'emplacement « Recherche INAMI ».
**Elle exclut le correctif Excel ADR-215** (PR #15 et #16 : H1, H2, H3 du récapitulatif) :

- son arbre est identique à `1e58091` (validé sur staging) **à l'exception des 38 fichiers de
  PR #15/#16, identiques à la production** (`git diff --name-only 1e58091 <B>` = `git diff --name-only 6ad8004 6da5ee7`, plus les deux documents de cette release) ;
- les deux ensembles de fichiers sont disjoints ; les fonctions ajoutées par ADR-215 sont privées
  au générateur Excel : aucune dépendance de la 3.8.0 ;
- aucun revert : `master` garde ADR-215 (statut « Proposée »). Après la mise en production,
  fusionner `release/3.8.0` dans `master` (commit de fusion) : `master` contiendra la 3.8.0 et
  ADR-215, comme aujourd'hui non déployé. La PR #17 (dont `1e58091` n'est pas repris tel quel)
  est alors à fermer comme remplacée.

La PWA de la release = `5a4887f` : `d9ff983` (PR #14, validée sur staging) + `fix/inami-cookies-consentement`
(cookie de spécialité sur choix explicite) + `fix/publicite-sans-identifiant-persistant` (plus
d'identifiant publicitaire persistant, `mc-ad-visitor` effacé au démarrage, fréquence du plein écran
minimale, `/cookies` 1.2) + `fix/contact-officiel` (adresse `contact@medclick.be` partout). Elle ne
contient aucun changement Excel. Le backend ajoute aux documents la même adresse dans l'e-mail
d'activation (aucune migration supplémentaire).

## Migrations

Deux migrations, additives, compatibles avec la version en service :

1. `Version20261108090000` : table `inami_search_stat`.
2. `Version20261109090000` : table `ad_placement_setting`, colonnes `marketing.partner_id`,
   `pending_changes`, contacts des entreprises ; rattachement des annonceurs par nom (`sponsor_name`).

`Version20261105090000` (partage de nomenclature) est identique octet pour octet à la production,
où elle est enregistrée le 03/11/2026 : rien n'est rejoué ni renommé. En production, les cinq
campagnes (1 à 5) n'ont pas de nom d'annonceur : **la migration n'en rattache aucune** et ne crée
aucune entreprise (relevé du 07/11/2026). Elles restent diffusées et comptées (« Sans entreprise ») ;
les modifier dans la nouvelle administration demandera de choisir une entreprise.

## 1. Prérequis

- Points juridiques de `MEP-3.8.0-COOKIES.md` tranchés ; `docs/conditions/conditions-3.8.0.html`
  validé (publication au § 7, sans nouvelle acceptation imposée).
- Boîte `contact@medclick.be` opérationnelle et copie interne configurée (`MEP-3.8.0-COOKIES.md`,
  § 8 bis), contrôlées par un message de test.
- Release `<B>`/`<F>` déployée et recettée sur staging.
- `./deploy.sh --verify 6ad8004eab83974a3a557f1622e2982ee437ea43 21b0d9639e79bc8b1b18203a1f30c9b93d8a4d71` : OK.
- Services sains, `error_log` sans erreur nouvelle (une erreur connue : `GET /` du backend par un robot, sans lien).
- Mémoire disponible du VPS suffisante pour la construction des images (> 2 Go).

## 2. Sauvegardes (avant toute modification)

1. `./backup.sh /opt/stack/apps/medclick-prod prod` : base chiffrée, `.sha256`, copie hors VPS.
   Les visuels publicitaires et logos sont **dans la base** (`marketing_image`, `partner_logo`).
2. Contrôle : `./restore.sh test <fichier>` (base jetable sans réseau) ; comptages identiques à la
   production (utilisateurs, années, interventions, campagnes, visuels).
3. Code et configuration : `cp -a src/pwa src/pwa.21b0d96` ; copie de `.env`, `docker-compose.yml`,
   `deploy.sh` dans `backups/config-avant-3.8.0-$TS/` ; `docker images | grep medclick-prod > backups/images-avant-3.8.0-$TS.txt`
   (les images `medclick-prod-backend:6ad8004` et `medclick-prod-frontend:21b0d96` sont gardées).
4. Noter le fichier de sauvegarde et les SHA de départ ci-dessus.

## 3. Déploiement

L'ancienne version reste en service pendant la construction et les migrations ; backend, worker
et PWA sont remplacés ensemble par `deploy.sh`.

1. PWA (poste) : `git -C <pwa-medclick> archive <F> frontend | ssh surgicalhub-prod "tar -x -C /opt/stack/apps/medclick-prod/src/pwa"`,
   puis `echo <F> > src/pwa/.commit`.
2. `./deploy.sh <B> <F>` : construction, puis arrêt attendu (code 2, « 2 migration(s) en attente »).
3. `./deploy.sh --migrate <B> <F>` : sauvegarde `backups/avant-migration-…`, puis les 2 migrations.
4. Contrôle de la version en service sur le schéma enrichi : connexion, tableau de bord, un export
   Excel, `error_log`.
5. `./deploy.sh <B> <F>` puis `./deploy.sh --verify <B> <F>` (code 0).
6. `app:attach-campaigns-to-company` : **ne pas exécuter** (décision du 07/11/2026 : aucune campagne
   rattachée à une entreprise fictive).

## 4. Contrôles après mise en ligne (production)

| Contrôle | Attendu |
|---|---|
| `GET /api/public/inami/specialities` (sans jeton) | 200, 9 spécialités ; somme des `count` = 1 475 (1 347 codes proposés, dont 128 partagés avec la neurochirurgie comptés deux fois) ; ortho 791 |
| `GET /api/public/inami/codes?speciality=ortho&q=genou` | 200, résultats, `Cache-Control: max-age=60, public`, `ETag` |
| Recherche par code (ex. `q=` un code hospitalier connu) | 200, le code dans les résultats |
| `…/codes?speciality=ortho&limit=1` | 400 |
| `/api/nomenclature/ortho`, `/api/surgeries`, `/api/admin/inami-stats` (sans jeton) | 401 |
| `GET /api/marketing/slots` | 200 ; campagnes 2 à 4 sur le tableau de bord, comme avant |
| `doctrine:migrations:status` | aucune migration en attente |
| Navigateur, ordinateur et smartphone : `/login` → « Trouver un code INAMI » → « Oui » | recherche, propositions sur toute la largeur, copie, aucun cookie de spécialité sans « Mémoriser », `/cookies` 1.2 ; après `/login`, aucune clé `mc-ad-visitor` (effacée si présente) |
| Connexion d'un compte existant, tableau de bord, export Excel d'une année | identiques à la 3.7.x (export **inchangé** : ADR-215 non livré) |
| Administration › Publicités & partenaires | neuf emplacements, « Recherche INAMI » sous « Pages publiques » ; statistiques |
| `inami_search_stat` après une visite | compteurs du jour |
| `error_log`, journaux | aucune erreur 5xx nouvelle |
| Page d'accueil, fenêtre des conditions à l'inscription, `/cookies`, encart partenaire | contact `contact@medclick.be`, aucune adresse `gmail` |

Aucune écriture de test en production sans décision explicite.

## 5. Conditions d'arrêt

Migration autre que les deux attendues, échec de `restore.sh test`, erreur 5xx, export Excel
différent de la 3.7.x, campagne existante non diffusée : arrêt, aucune correction improvisée.

## 6. Retour arrière

| Situation | Action |
|---|---|
| Code seul (avant ou sans écriture dans la nouvelle administration) | `rm -rf src/pwa && cp -a src/pwa.21b0d96 src/pwa`, puis `./deploy.sh 6ad8004eab83974a3a557f1622e2982ee437ea43 21b0d9639e79bc8b1b18203a1f30c9b93d8a4d71` (l'ancien code ignore les nouvelles tables et colonnes) |
| Schéma | `doctrine:migrations:migrate 'DoctrineMigrations\Version20261107090000' --no-interaction` (descend `…109…` puis `…108…`), après le retour du code |
| Échec de migration ou écritures à annuler | `./restore.sh into /opt/stack/apps/medclick-prod <sauvegarde du § 2>`, décision explicite, perte des écritures postérieures |

## 7. Publication des conditions d'utilisation (après le § 4, sur décision)

Aucun écran d'administration : l'API sert la ligne de `terms_conditions` la plus récente
(`published_at`). Texte : `docs/conditions/conditions-3.8.0.html`, tel que validé.

1. Sauvegarde : `./backup.sh /opt/stack/apps/medclick-prod prod` (ou celle du § 2 si aucune
   écriture depuis).
2. Insertion d'une ligne (`content` = le fichier, `published_at` = date du jour) ; la ligne n° 1
   (17/12/2024) est conservée.
3. Contrôle : `GET /api/terms-conditions` → nouvelle date, aucune occurrence de `gmail`, quatre
   liens `contact@medclick.be` ; affichage des conditions dans l'application.
4. Retour arrière : suppression de la ligne insérée (la version du 17/12/2024 redevient servie).

Aucune nouvelle acceptation n'est demandée aux utilisateurs (acceptation non versionnée).
