# CLAUDE.md — MedClick backend

API REST de **MedClick** (medclick.be) : carnet de stage numérique pour les médecins assistants
(résidents) en chirurgie en Belgique. Un résident enregistre, par année de formation, ses
interventions, consultations, gardes, formations et superviseurs, puis exporte son
**carnet de stage officiel en Excel**. Un back-office admin gère la nomenclature INAMI,
les utilisateurs, les statistiques de connexion et des campagnes marketing.

Le frontend (SPA, repo séparé) consomme cette API. Documentation détaillée : `docs/`.

## Stack

- **PHP 8.4** (production : Hostinger, LiteSpeed ; dev local : WAMP avec PHP 8.4), **Symfony 8.1**
  (version non LTS : suivre 8.2, 8.3… jusqu'à la LTS 8.4, prévue en novembre 2027)
- **API Platform 4** (`#[ApiResource]`, namespace `ApiPlatform\Metadata`, state processors dans `src/State/`)
- Doctrine ORM 3 / DBAL 4 / DoctrineBundle 3 (objets paresseux natifs PHP 8.4) + MySQL (`DATABASE_URL`)
- Auth : **LexikJWT 3** (`POST /api/login_check`, body `{username, password}`), stateless,
  authenticators Symfony (`jwt: ~`, `login_throttling`). Limites par IP sur l'inscription
  et le reset : `config/packages/rate_limiter.yaml` + `src/Security/PublicEndpointRateLimiter.php`.
- PhpSpreadsheet (export carnet de stage), Symfony Mailer (Gmail), Twig (emails)
- Tests fonctionnels : `tests/` (ApiTestCase, base SQLite `var/test.db` recréée à chaque test).
- CI : `.github/workflows/ci.yml` (lint conteneur, `doctrine:schema:validate`, tests). Le dépôt
  GitHub est **public** : ne jamais committer de secret, ni de détail d'une faille non déployée.

## Commandes

```bash
cp .env.example .env                      # première installation, puis renseigner les valeurs
composer install                          # lance aussi cache:clear + migrations (auto-scripts)
composer test                             # PHPUnit 12 (sans Xdebug)
symfony serve  |  php -S 0.0.0.0:8000 -t public
php bin/console debug:router              # liste des routes custom + API Platform
php bin/console doctrine:migrations:diff  # générer une migration après modif d'entité
php bin/console doctrine:migrations:migrate
php bin/console app:update-surgeries      # relie Surgeries -> Nomenclature (lots de 40 000)
php bin/console app:update-favorites      # relie Favorites -> Nomenclature
php bin/console lexik:jwt:generate-keypair  # clés dans config/jwt/*.pem (gitignorées)
php bin/console doctrine:schema:validate --skip-sync   # vérifie le mapping
```

Sans `serverVersion` dans `DATABASE_URL`, `cache:clear` en env dev exige MySQL démarré
(détection de version) ; les tests n'en ont pas besoin (SQLite).

Doc OpenAPI générée par API Platform : `GET /api` (ne couvre pas les contrôleurs custom).

## Architecture (où chercher quoi)

- `src/Entity/` — modèle. Pivot = `Years` (année de formation d'un `User`) ; `Surgeries`,
  `Surgeons`, `Consultations`, `Gardes`, `Formations` sont rattachés à une `Years`.
  `Nomenclature` = référentiel INAMI ; `Favorites` = raccourcis d'intervention par user.
- `src/Doctrine/CurrentUserExtension.php` — **filtre multi-tenant** : restreint les lectures
  API Platform (et la résolution des IRI) aux données de l'utilisateur connecté. Ne
  s'applique PAS aux contrôleurs custom (`findOneBy` direct).
- `src/Security/Voter/OwnershipVoter.php` — attribut `OWNER` : la ressource appartient-elle
  au user ? Utilisé en `securityPostDenormalize` sur les `#[ApiResource]` et via
  `$this->isGranted('OWNER', $x)` dans les contrôleurs custom.
- `src/State/` — state processors API Platform branchés par opération (`processor:`) :
  `UserProcessor` (hash du mot de passe, email d'activation), `CurrentUserAssignProcessor`
  (propriétaire des `Years`/`Favorites`), `SurgeonProcessor` (maître de stage unique),
  `SurgeonRemoveProcessor` (interventions supprimées avec le chirurgien), `FormationProcessor`.
- `src/Events/JwtCreatedSubscriber.php` — claims ajoutés au JWT.
- **Capture des erreurs** : `src/Service/ErrorRecorder.php` (table `error_log`, regroupement par
  empreinte), `src/EventListener/ErrorCaptureSubscriber.php` (réponses >= 500 et erreurs console,
  enregistrées après l'envoi de la réponse), `POST /api/client-errors` (fronts),
  `/api/admin/errors` (consultation). Ne jamais y stocker de corps de requête, mot de passe ou IP.
  Les fronts envoient leurs erreurs via `src/js/Services/errorReporter.js` (PWA et admin).
- `tests/Api/FrontCompatibilityTest.php` — contrat attendu par les fronts React (`hydra:member`,
  `PUT` partiel, `violations`, dates ISO 8601) : ne pas le casser.
- `src/Security/UserChecker.php` — bloque le login si compte non activé (`token` non null) et
  journalise la connexion dans `ConnectionHistory`.
- `src/Controller/` — endpoints custom (JSON manuel). `AdminControllers/` sous `/api/admin/*`
  (ROLE_ADMIN via `access_control`). `ExcelNewVersion.php` (`/api/excel2/{year}`) est l'export
  courant ; `ExcelGeneratorController.php` (`/api/excel/{year}`) est l'ancienne version.
- `public/ExcelTemplate.xlsx` — gabarit du carnet de stage (chargé via `kernel.project_dir`).
- Contrôle d'accès : `config/packages/security.yaml` (`access_control` par préfixe d'URL).

## Conventions du code existant

- Code, commentaires, messages d'erreur et commits **en français**.
- Attributs PHP 8 uniquement : `#[Route]`, `#[ApiResource]`, `#[ORM\...]`, `#[Groups]`, `#[Assert\...]`
  (les annotations en docblock ne sont plus lues).
- Contrôleurs custom : `json_decode($request->getContent(), true)` + `JsonResponse`.
- Les nouvelles versions d'endpoints coexistent avec les anciennes pour ne pas casser les
  anciens frontends (ex. `/api/surgeries/add` vs `/api/surgeries/addNewSurgery`,
  `/api/excel` vs `/api/excel2`). Ne pas supprimer une ancienne route sans vérifier le front.
- Champs `position` d'une intervention : `1` = 1re main, `2` = 2e main (aide),
  `3` = 1re main aidée. `firstHand`/`secondHand` stockent un id (string).
- Code d'intervention = `codeHospitalisation . n` de la nomenclature.

## Pièges connus

- Modifier une entité ⇒ **générer une migration** (seules 2 migrations existent, le schéma
  historique a été géré hors migrations : vérifier `doctrine:schema:validate`).
- Tout nouveau contrôleur custom qui charge une ressource par id doit **vérifier
  `isGranted('OWNER', ...)`** ; toute nouvelle entité exposée doit déclarer ses opérations
  avec `security_post_denormalize` et être ajoutée à `OwnershipVoter` et `CurrentUserExtension`.
  Ajouter un test dans `tests/Api/OwnershipTest.php`.
- `User` : seuls les champs du groupe `user_write` (email, password, firstname, lastname,
  speciality) sont inscriptibles via l'API. Ne jamais y ajouter `roles`, `token`, `resetToken`.
- `.env` n'est pas versionné et contient des valeurs réelles : ne jamais le committer ni le
  recopier dans la doc ; utiliser `.env.local`.
- Le mailer utilise `templates/email/*.html.twig` ; le lien d'activation pointe sur
  `/activation/{token}` qui redirige vers `https://www.medclick.be/#/login`.

## Docs

- `docs/ARCHITECTURE.md` — domaine, modèle de données, endpoints, flux.
- `docs/DECISIONS.md` — décisions d'architecture (ADR) existantes et proposées.
- `docs/AUDIT.md` — audit et feuille de route pour une application professionnelle.
- `docs/DEPLOIEMENT.md` — procédure de mise en production (Hostinger), à suivre à la lettre.
- `docs/Design/medclick-react/` — maquette du nouveau front (React + TypeScript), alignée sur ce backend ;
  `docs/API.md` y liste les routes encore à créer. `docs/Design/A-ALLEGER.md` — ce qui pourra être retiré.
