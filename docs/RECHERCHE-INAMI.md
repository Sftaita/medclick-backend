# Recherche publique des codes INAMI

Moteur public et gratuit de recherche des codes INAMI (`/codes-inami` du front), sans compte.
Design et spécifications validés : `docs/Design/recherche-inami/` (`SPECIFICATIONS.md`).
Front : dépôt `pwa-medclick`, `frontend/src/features/inami/`.

## Routes (backend)

| Route | Accès | Rôle |
|---|---|---|
| `GET /api/public/inami/specialities` | public, limité (`inami_search`, 240/min/IP) | `{ specialities: [{ id, count }] }` : spécialités qui proposent au moins un code aujourd'hui. Aucun paramètre accepté. |
| `GET /api/public/inami/codes` | public, limité (même limiteur) | `?speciality=<id>\|all&q=&page=&sort=relevance\|name\|nk&order=asc\|desc` → `{ items: [{ name, codeAmbulant, codeHospitalisation, n, speciality }], total, page, pageSize: 25, pages }` |
| `POST /api/public/inami/events` | public, limité (`inami_event`, 30/min/IP) | `{ type: "visit" }` ou `{ type: "speciality", speciality }` : compteurs quotidiens. 204. |
| `GET /api/admin/inami-stats?days=30` | ROLE_ADMIN | visites par jour, spécialités consultées. |

- **Données** : nomenclature lue à chaque appel (`NomenclatureRepository::fetchPublicCodes`), mêmes règles que les listes de sélection : codes actifs, sans abrogation vérifiée atteinte, codes partagés (`nomenclature_shared_speciality`) compris. Aucune copie, aucun identifiant interne exposé.
- **Recherche** (`PublicNomenclatureSearch`) : insensible à la casse et aux accents ; plusieurs mots = tous présents ; chiffres seuls (au moins 2, espaces/points/tirets ignorés) = code ambulatoire ou hospitalisation. Filtrage en PHP (identique sous MySQL et SQLite) : quelques milliers de codes au plus par spécialité.
- **Validation stricte** : paramètre inconnu, spécialité non proposée, page hors 1..400, tri inconnu, recherche de plus de 100 caractères ou contenant des caractères de contrôle → 400.
- **Cache** : `Cache-Control: public, max-age=60` + `ETag` (304). Une modification de la nomenclature est visible en une minute au plus.
- L'ancienne liste protégée `GET /api/nomenclature/{speciality}` **reste protégée** (JWT).

## Publicité : emplacement « Recherche INAMI » (`inami_search`)

Ajouté au système de campagnes existant (aucun module dupliqué) :

- `Marketing::PLACEMENT_INAMI_SEARCH`, dans `PLACEMENTS` et `CARD_PLACEMENTS` (servi par `GET /api/marketing/slots`, clé `inami_search`, mesuré par `POST /api/marketing/events`, statistiques « Recherche INAMI »).
- `Marketing::PUBLIC_ONLY_PLACEMENTS` : `SponsorDelivery` ne sert **jamais** une campagne `professionals_only` sur cet emplacement, **même à un utilisateur connecté**, et n'y compte aucun événement pour une telle campagne. Garantie côté serveur, indépendante de l'administration.
- `Marketing::STANDALONE_PLACEMENTS` + `MarketingController` : refus d'une campagne `professionals_only` sur cet emplacement, et de toute combinaison avec un autre emplacement (formats différents).
- Visuels : `bannerDesktop` (1200 × 300, 4:1) et `bannerMobile` (640 × 200) de la campagne. Les anciennes bannières 1200 × 150 (8:1) restent acceptées : la page les affiche entières, dans une bande à leurs proportions. Import : `POST /api/admin/marketing-images?format=banner` (480 × 120 px au minimum ; les autres visuels gardent 480 px par côté).
- Administration (front) : onglet « Recherche INAMI » de Communication › Publicités, indépendant de « Accueil » et « Connexion ».
- Mesure sur la page : identifiant de visiteur gardé **en mémoire** le temps de la page (aucun stockage navigateur) ; le dédoublonnage du serveur ne vaut donc que pendant la visite.

## Statistiques

Table `inami_search_stat` (migration `Version20261108090000`, additive) : `day`, `kind` (`visit` | `speciality`), `speciality`, `count`. Aucune IP, aucun visiteur, aucun terme de recherche. Affichage : Communication › Statistiques, section « Recherche INAMI ».

## Intégration réalisée : branche `integration/recherche-inami-refonte`

`master` (`6da5ee7`) + `feature/refonte-publicites-partenaires` (`9b0fd54`) + `feature/recherche-inami`,
dans un worktree dédié (branches d'origine inchangées) :

- **Migrations** : `Version20261105090000` de `master` (partage de nomenclature, exécutée en
  production avec la 3.7.0) garde son nom ; celle de la refonte devient `Version20261109090000`
  (contenu inchangé). Ordre d'exécution sur une base au niveau de la production :
  `Version20261108090000` (statistiques INAMI) puis `Version20261109090000` (refonte). Préalable
  vérifié avant tout déploiement : aucune base persistante n'a exécuté la migration de la refonte
  sous l'ancien nom (`doctrine_migration_versions`).
- **`SponsorDelivery.php`** : résolution ci-dessous (sélection de la refonte + audience publique pour
  `inami_search`).
- **Nouveau système** : emplacement `inami_search` dans `Emplacements` (écran « Pages publiques »,
  publicités, capacité 3, ordinateur et mobile, public), formats recommandés 1200 × 150 / 640 × 200
  dans `EmplacementManager::recommended()`, refus d'une publicité réservée aux professionnels avec
  un message propre, refus de la reprise d'une publicité INAMI sur un autre emplacement (et
  inversement). Absence de ligne `ad_placement_setting` : emplacement activé par défaut (aucune
  migration supplémentaire).
- **Tests** : `EmplacementAdminTest` (neuf emplacements ; parcours INAMI dans l'administration par
  emplacement, publication, diffusion, exclusion des publicités réservées).

## Dépendance : `feature/refonte-publicites-partenaires`

Branche non fusionnée au 04/11/2026, en retard de 33 commits sur `master`. Points relevés :

1. **Conflit de migration** (préexistant, indépendant de la recherche INAMI) : la refonte ajoute
   `migrations/Version20261105090000.php`, **même nom de classe** qu'une migration déjà présente sur
   `master` (`nomenclature_shared_speciality`). `git merge-tree` signale un conflit add/add entre la
   refonte et `master`, avec ou sans cette branche. Aucune migration n'a été renommée ici : avant de
   renommer celle de la refonte, vérifier dans chaque base (`doctrine_migration_versions`) laquelle des
   deux a déjà été exécutée sous ce nom (production, préproduction, postes de développement), sans quoi
   Doctrine la considérerait comme appliquée et ne l'exécuterait jamais.
2. **Intégration de « Recherche INAMI »** une fois la refonte fusionnée (ou rebasée sur cette branche) :
   - `src/Advertising/Emplacements.php` : ajouter une entrée `inami_search` (écran `pages_publiques`, `kind: ads`, `capacity: 3`, `supports: { desktop: inami_search, mobile: inami_search }`, `sameCampaignForSupports: true`, `public: true`) et son libellé dans `STAT_LABELS`. Sans cette entrée, `SponsorDelivery::selectFor()` de la refonte renvoie une liste vide pour `inami_search` : **rien ne casse**, la page affiche l'encart MedClick.
   - Conserver dans `SponsorDelivery` la règle `PUBLIC_ONLY_PLACEMENTS` (audience publique pour `inami_search`, y compris dans `isServable`).
   - Reporter dans l'administration de la refonte (par emplacement) les formats 1200 × 300 / 640 × 200, l'import `?format=banner` et le refus `professionals_only`.
   - Aucun emplacement existant n'est supprimé, renommé ni modifié.
3. Ordre recommandé : fusionner la refonte (après correction du point 1), puis rebaser `feature/recherche-inami` et appliquer le point 2 ; ou l'inverse, la refonte reprenant alors le point 2 dans son rebase.

**Fusion simulée** (`git merge-tree`, 04/11/2026, refonte `9b0fd54` + cette branche) : en plus du
conflit de migration déjà présent entre la refonte et `master`, un seul conflit, dans
`src/Service/SponsorDelivery.php` (`slots()` et `isServable()` modifiés des deux côtés). Résolution :

```php
// slots() : sélection de la refonte, audience publique pour la recherche INAMI.
$slots[$placement] = $this->selectFor(in_array($placement, Marketing::PUBLIC_ONLY_PLACEMENTS, true) ? $public : $live, $placement, $this->today());

// isServable() : réglages de l'emplacement (refonte) + règle publique (recherche INAMI).
$emplacement = Emplacements::forCampaignPlacement($placement);
$publicOnly = !$authenticated || in_array($placement, Marketing::PUBLIC_ONLY_PLACEMENTS, true);

return in_array($placement, Marketing::PLACEMENTS, true)
    && $emplacement !== null && $this->settings->isEnabled($emplacement)
    && (!$publicOnly || !$campaign->isProfessionalsOnly())
    && $campaign->isLiveOn($this->today())
    && $campaign->hasPlacement($placement)
    && self::hasVisualFor($campaign, $placement);
```

Les autres fichiers communs (`Marketing.php`, `MarketingImageStore.php`, `CLAUDE.md`) fusionnent
automatiquement. Les tests `InamiSearchPlacementTest` vérifient la règle après fusion.

## Cookies et informations légales

| Ce qui est implémenté (technique) | Ce qui demande une validation juridique |
|---|---|
| Cookie `mc_inami_specialite` (identifiant de spécialité, 12 mois, `SameSite=Lax`, `Secure` en HTTPS) ; cookie de session `mc_inami_pro` (`1`) ; aucun autre stockage pour cette page. | Confirmer que ces deux cookies fonctionnels, choisis par l'utilisateur, sont exemptés de consentement (loi du 13 juin 2005, art. 10/2 ; lignes directrices de l'APD). Aucun bandeau n'a été ajouté. |
| Page publique `/cookies` : description factuelle (cookies, mesure publicitaire sans stockage, statistiques agrégées, suppression). Lien « En savoir plus » au moment du choix de la spécialité et « Cookies » en pied de page. | Mettre à jour les **conditions d'utilisation** (publiées depuis l'administration, `/api/terms-conditions`, versionnées) avec le texte proposé dans `docs/Design/recherche-inami/README.md`, puis décider si une nouvelle acceptation est exigée. |
| Le texte « c) Cookies et sécurité » de `ConditionsDialog.jsx` (inscription) n'a **pas** été modifié. | Le revoir avec les conditions publiées (texte historique distinct). |
| Mesure publicitaire de la page sans identifiant persistant. | Les autres pages publiques (connexion) gardent l'identifiant `mc-ad-visitor` (localStorage) du système existant : à examiner dans la même revue. |

## Tests

Comparaison du 05/11/2026 (un processus PHPUnit par fichier, Windows, PHP 8.4) :

| Branche | Tests | Réussis | Échecs / erreurs | Ignorés |
|---|---|---|---|---|
| `master` (`5114fea`) | 462 | 461 | 0 | 1 |
| `feature/recherche-inami` | 480 | 479 | 0 | 1 |

Aucune régression ; 18 tests ajoutés (`PublicInamiSearchTest`, `InamiSearchPlacementTest`, format
bannière de `MarketingImageTest`), tous réussis.

**Problème préexistant (non corrigé ici)** : sur ce poste, la suite complète lancée en un seul
processus (`composer test`) s'interrompt sur `master` comme sur cette branche (« Allowed memory size
… exhausted » dans `CarnetDeStageTemplate.php`, test variable : `ExcelReferenceTest`,
`ExportAccessTest`). `GenerateExportHandler` abaisse temporairement `memory_limit` à la valeur des
réglages d'export (512 Mo) alors que la mémoire accumulée par les tests précédents du même processus la
dépasse déjà. Les exports eux-mêmes ne sont pas en cause (tests réussis fichier par fichier) ; à traiter
séparément (isolation des tests d'export, ou limite relevée en environnement de test).

Des 401 isolés (jeton JWT expiré) ont été observés pendant une mise en veille du poste ; les fichiers
concernés réussissent sur les deux branches une fois relancés.

## Avant la mise en production

1. Fusionner les PR backend puis front (ordre : backend d'abord, la clé `inami_search` étant ignorée par l'ancien front).
2. Appliquer la migration `Version20261108090000` (création d'une table, réversible par `down()`), selon `docs/DEPLOIEMENT.md`.
3. Vérifier en préproduction : `/codes-inami` sans compte, réponse 401 de `/api/nomenclature/ortho` sans jeton, 429 au-delà des limites, cache `max-age=60`.
4. Créer la première publicité « Recherche INAMI » dans l'administration (ou laisser l'encart MedClick).
5. Valider et publier le texte cookies des conditions d'utilisation (voir ci-dessus).
