# Espaces sponsors

Emplacements publicitaires commercialisables de MedClick : quelques encarts visibles et sobres,
gérés sans modification de code, mesurés de façon crédible pour les annonceurs.

À distinguer du **partenaire** (`Partner`, `docs/ARCHITECTURE.md`) : un seul sponsor « institutionnel »
affiché dans la barre latérale et la page « Nos partenaires ». Il reste le remplacement des encarts
quand aucune campagne n'est diffusée.

## Emplacements

| Code | Où | Visuel | Vendu comme |
|---|---|---|---|
| `dashboard_desktop` | Tableau de bord ≥ 900 px, colonne de droite sous « Année en cours » | bannière 16:9 (1600 × 900) | Premium / secondaire |
| `dashboard_mobile` | Tableau de bord < 900 px, juste après « Bonjour », avant le carnet | bannière 2:1 (1200 × 600) | Premium / secondaire |
| `login` | Connexion : sur la photo (≥ 1200 px) ou sous le formulaire | 16:9 sur grand écran, 2:1 sinon | Sponsor de connexion |
| `login_interstitial` | Plein écran après la connexion (format historique) | formats `*_format` | campagnes existantes |

Une bannière absente est remplacée par l'autre format (recadrage centré, jamais de déformation).
Les campagnes existantes ont reçu `login_interstitial` à la migration : leur affichage n'a pas changé.

**Depuis la PR « partenaires par emplacement »** :

- `login` : 16:9 dès 768 px (même palier que le tableau de bord), 2:1 en dessous.
- `login_interstitial` : `GET /api/marketing/active` est public. Le front le demande à
  l'ouverture de la page de connexion (jamais depuis un cache) et affiche la campagne dès le clic
  sur « Se connecter », pendant l'authentification ; au plus une fois par jour et par campagne.
  Sélection par `SponsorDelivery::interstitial()` sur toutes les campagnes : une exclusive
  l'emporte, sinon tirage pondéré par la priorité. Visiteur non connecté et campagne tirée
  réservée aux professionnels : `{"after_login": true}` sans aucun contenu ; le front la
  redemande après une connexion réussie. Compteurs `views`/`clicks` absents pour un visiteur.
  Impression envoyée seulement après une connexion réussie.
- `professionals_only` : campagne jamais servie à un visiteur non connecté (`/slots`, mesure) et
  refusée sur `login` (page publique).
- L'activation par `PUT /api/admin/marketing/{id}/status` contrôle aussi les exclusivités.
- Champ public `exclusive` : le front masque la firme de la barre latérale quand une campagne
  exclusive est affichée (exclusivité d'écran). Firmes partenaires : docs/PARTENAIRES.md.

## Diffusion (`App\Service\SponsorDelivery`)

Campagne diffusée = statut `active`, date de début atteinte, date de fin non dépassée (jours entiers,
Europe/Brussels), emplacement coché et visuel disponible pour cet emplacement.

Par emplacement :

1. une campagne **exclusive** est seule (la plus prioritaire s'il y en a plusieurs ; l'administration
   refuse deux exclusivités actives qui se chevauchent sur un même emplacement) ;
2. sinon **3 campagnes au plus** (`MAX_ROTATION`), ordre = priorité décroissante (1 à 10) ;
3. au-delà de 3, tirage pondéré par la priorité (Efraimidis-Spirakis) à chaque chargement : à
   priorité égale, rotation équitable ; priorité double, deux fois plus de chances.

Offres (`offer`, libellé des rapports) : `premium` (tableau de bord, priorité 10, exclusif),
`login` (connexion, priorité 8), `secondary` (tableau de bord, priorité 5, carrousel partagé). Ce sont
des préréglages : emplacements, priorité et exclusivité restent modifiables. Pas de paiement en ligne.

## Mesure

Une **impression** est envoyée par le front seulement quand la publicité est **réellement vue** :
diapositive affichée (jamais une diapositive chargée en arrière-plan du carrousel), au moins 50 %
de sa surface visible, onglet au premier plan, pendant **1 seconde continue** (norme IAB/MRC). Sans
`IntersectionObserver`, rien n'est compté. Le front n'envoie qu'une impression par campagne et par
emplacement pendant la vie de la page.

Le serveur (`POST /api/marketing/events`) :

- refuse les types et emplacements inconnus (400) ; ignore (`recorded: false`) une campagne
  inconnue, non diffusée aujourd'hui ou pas sur cet emplacement ;
- ignore les **doublons** du même visiteur sur 30 minutes (même campagne, emplacement, type),
  y compris de part et d'autre de minuit (empreintes du jour et de la veille comparées) ;
- visiteur = utilisateur connecté (`u:<id>`) sinon identifiant aléatoire du navigateur (`a:<uuid>`,
  localStorage), jamais stocké tel quel : empreinte `HMAC-SHA256(APP_SECRET, visiteur | jour)` qui
  change chaque jour et est **effacée après 24 h** ;
- est limité à 60 événements par minute et par IP (`marketing_event`, `rate_limiter.yaml`).

Les **clics** suivent les mêmes règles (clic sur le visuel ou sur « Découvrir le partenaire »). Les
liens sortants : `target="_blank" rel="noopener noreferrer sponsored"`, URL http(s) uniquement.

`GET /api/marketing/slots` et `GET /api/marketing/active` sont en **lecture seule** : un appel d'API ne
compte plus rien.

### Historique

Avant cette version, `GET /api/marketing/active` créait une ligne `marketing_view` à chaque appel (y
compris le préchargement, sans affichage) et `Marketing.views` était incrémenté. Ces lignes sont
devenues `kind = legacy_view` ; elles et les compteurs `views` / `clicks` de la campagne sont présentés
à part (« historique, non comparable ») et **jamais additionnés** aux impressions mesurées.
`PUT /api/marketing/incrementCampaign/{id}` est conservé pour les anciens fronts (compteur historique).

## RGPD

Aucune donnée médicale ni nominative dans la mesure : ni IP, ni identifiant d'utilisateur en clair,
ni page ou donnée du carnet. Les rapports aux annonceurs sont des **agrégats** (jour, campagne,
emplacement). L'export CSV neutralise les cellules commençant par `= + - @` (injection de formule).

## API

| Méthode | Route | Accès | Rôle |
|---|---|---|---|
| GET | `/api/marketing/slots` | public | `{ login, dashboard_desktop, dashboard_mobile }` : campagnes publiques (id, sponsor, title, description, logo, imageDesktop, imageMobile, url, cta) |
| POST | `/api/marketing/events` | public, limité | `{ campaign, placement, type: impression\|click, visitor }` → 202 `{ recorded }` |
| GET | `/api/admin/marketing…` | admin | CRUD des campagnes (champs ci-dessous) ; liste avec `impressions`, `tracked_clicks`, `ctr` |
| GET | `/api/admin/marketing-stats` | admin | rapport : `from`, `to` (30 derniers jours par défaut, 366 jours max), `campaign`, `sponsor` |
| GET | `/api/admin/marketing-stats.csv` | admin | même rapport en CSV (`;`, UTF-8 avec BOM) |

Champs ajoutés à `Marketing` (migration `Version20261101090000`) : `sponsor_name`, `offer`, `title`,
`description`, `logo` (nom de fichier d'un logo envoyé par `POST /api/admin/partner-logos`),
`banner_desktop`, `banner_mobile` (visuels importés par `POST /api/admin/marketing-images`),
`show_cta`, `placements` (JSON), `priority` (1-10), `exclusive`. Champs ajoutés à `marketing_view` :
`kind` (`impression`, `click`, `legacy_view`), `placement`, `viewer_hash`. Une requête sans ces champs
(ancienne administration) crée une campagne plein écran, comme avant.

## Front (dépôt pwa-medclick)

`frontend/src/features/ads/` : `SponsorCarousel` (carrousel : 6 s, fondu, pause au survol, au focus,
après une interaction, onglet masqué ou hors écran, bouton pause, balayage, flèches, clavier,
`prefers-reduced-motion`), `SponsorFallback` (carte MedClick sans campagne), `hooks.js`
(`useViewableImpression`), `sponsors.js` (données, cache 5 min, envoi des événements). Administration :
`frontend/src/admin/marketing/` (`CampaignDialog`, `MarketingStatsPage`).

## Mise en production

Vérifié sur une copie de la structure de production (MariaDB, schéma 3.2.0 + données représentatives :
« Test Arthrex » et ses images collées tronquées, URL externes, visuels importés, 2 109 vues) :
empreinte des données existantes identique avant et après la migration, schéma conforme au mapping
(`doctrine:schema:validate`), migration descendante revenant à l'octet près à l'état initial.

### Vérification finale sur MariaDB 11.8 (préproduction, avant la production)

Vérification locale faite sur MariaDB 11.3.2 ; la production est en 11.8 : rejouer sur la
préproduction (`mariadb:11.8`) avec une copie fraîche de production (« Copie production →
préproduction », `docs/DEPLOIEMENT-VPS.md`). Script en lecture seule : `deploy/vps/sponsors-verification.sql` (empreintes MD5 par ligne combinées
par `BIT_XOR`, sans `GROUP_CONCAT` : celui-ci est tronqué sans erreur au-delà de
`group_concat_max_len`, 1 Mo, et ne verrait plus les dernières lignes d'une grosse table).

```bash
D=/opt/stack/apps/medclick-staging; cd $D
C="docker compose -f src/backend/deploy/vps/docker-compose.staging.yml --project-directory ."
SQL='mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" --force'
$C exec -T db sh -c "$SQL" < src/backend/deploy/vps/sponsors-verification.sql > avant.txt   # partie 2 : erreurs normales
./deploy.sh <sha_backend> <sha_front_actuel>                                                 # s'arrête sur la migration
$C exec backend php bin/console doctrine:migrations:migrate -n
$C exec backend php bin/console doctrine:schema:update --dump-sql                           # seul écart admis : DROP INDEX wdbi_name_191_031fbf8277a9 (voir ci-dessous)
$C exec -T db sh -c "$SQL" < src/backend/deploy/vps/sponsors-verification.sql > apres.txt
diff <(grep -E '^(marketing|partner)' avant.txt) <(grep -E '^(marketing|partner)' apres.txt) && echo "EMPREINTES IDENTIQUES"
# Test du retour arrière, puis remontée :
$C exec backend php bin/console doctrine:migrations:migrate prev -n
$C exec -T db sh -c "$SQL" < src/backend/deploy/vps/sponsors-verification.sql > descente.txt
diff <(grep -E '^(marketing|partner)' avant.txt) <(grep -E '^(marketing|partner)' descente.txt) && echo "RETOUR ARRIÈRE IDENTIQUE"
$C exec backend php bin/console doctrine:migrations:migrate -n
./deploy.sh <sha_backend> <sha_front>
```

Index préexistant : la production porte un index `wdbi_name_191_031fbf8277a9` sur `nomenclature(name(191))`,
ajouté hors de l'application avant la 3.2.0 (présent dans le dump `prod-avant-3.2.0`). Absent du mapping,
il fait toujours répondre « not in sync » à `doctrine:schema:validate`. Sans lien avec cette version :
ne pas le supprimer ici (il sert la recherche dans la nomenclature) ; à intégrer au mapping séparément.

Attendu dans `apres.txt` : une seule ligne `["login_interstitial"] 5 0 0 <nombre de campagnes>` et
une seule ligne `legacy_view login_interstitial <nombre de vues> 0`. Puis contrôles manuels :
campagnes existantes visibles et modifiables dans l'administration, plein écran toujours affiché
à la connexion, `GET /api/marketing/slots` en 200, statistiques : « vues historiques » séparées.

Ordre (procédure `docs/DEPLOIEMENT-VPS.md`) :

1. **Sauvegarde** de production (`backup.sh`) juste avant, et contrôle de son SHA-256.
2. **Backend** : `./deploy.sh <sha_backend> <sha_front_actuel>` ; il s'arrête sur la migration en
   attente. Entre ces deux étapes, les routes marketing échouent (colonnes absentes) : le front
   « fail-open » n'affiche simplement pas de publicité. Enchaîner aussitôt :
3. **Migration** : `docker compose exec backend php bin/console doctrine:migrations:migrate -n`, puis
   `doctrine:schema:update --dump-sql` (seul écart admis : l'index préexistant ci-dessous),
   `deploy/vps/sponsors-verification.sql` (empreintes identiques) et `GET /api/marketing/slots` (200).
   Relancer ensuite `./deploy.sh` (worker et frontend ne redémarrent pas tant qu'une migration attend).
4. **Frontend** : nouvelle archive dans `src/pwa` + `.commit`, puis `./deploy.sh <sha_backend> <sha_front>`.
   L'ancien front fonctionne avec le nouveau backend (plein écran, clics historiques) ; seuls ses
   affichages plein écran ne sont plus comptés tant que le nouveau front n'est pas en ligne.

Ne jamais appliquer la migration avec l'ancien code : `placements` est obligatoire (création de
campagne refusée par l'ancienne administration) et l'ancien `GET /api/marketing/active` enregistrerait
ses lignes comme des impressions réelles.

## Retour arrière

- **Frontend seul** (sans risque) : `./deploy.sh <sha_backend> <sha_front_précédent>`. L'ancien front
  ignore les nouveaux emplacements ; le nouveau front sans le nouveau backend n'affiche rien
  (`/slots` absent → carte de remplacement).
- **Backend** (toujours après le frontend) :
  1. désactiver les campagnes sans format plein écran, que l'ancien code tirerait au sort sans pouvoir
     les afficher :
     `UPDATE marketing SET status = 'inactive' WHERE JSON_CONTAINS(placements, '"login_interstitial"') = 0;`
  2. `doctrine:migrations:migrate prev -n` : supprime les colonnes ajoutées et les impressions et clics
     mesurés (exporter les statistiques en CSV avant) ; campagnes, visuels et vues historiques intacts ;
  3. `./deploy.sh <sha_backend_précédent> <sha_front_précédent>`.
- **En dernier recours** : restaurer la sauvegarde de l'étape 1 (`restore.sh`) ; perd toutes les
  écritures postérieures (carnets compris) : à n'utiliser que si la migration a échoué en cours de route.
