# CLAUDE.md — MedClick backend

API REST de **MedClick** (medclick.be) : carnet de stage numérique pour les médecins assistants
(résidents) en chirurgie en Belgique. Un résident enregistre, par année de formation, ses
interventions, consultations, gardes, formations et superviseurs, puis exporte son
**carnet de stage officiel en Excel**. Un back-office admin gère la nomenclature INAMI,
les utilisateurs, les statistiques de connexion et des campagnes marketing.

Le frontend (SPA, repo séparé) consomme cette API. Documentation détaillée : `docs/`.

## Stack

- **PHP 8.2** (production : Hostinger, LiteSpeed, PHP 8.2 ; dev local WAMP), **Symfony 5.4 LTS**
- **API Platform 2.6** (annotations `@ApiResource`, namespace `ApiPlatform\Core`)
- Doctrine ORM 2.20 / DBAL 3 / DoctrineBundle 2.13 + MySQL (`DATABASE_URL`), annotations `@ORM\...`
- Auth : **LexikJWT** (`POST /api/login_check`, body `{username, password}`), stateless
- PhpSpreadsheet (export carnet de stage), Symfony Mailer (Gmail), Twig (emails)
- Tests fonctionnels : `tests/` (ApiTestCase, base SQLite `var/test.db` recréée à chaque test).
- CI : `.github/workflows/ci.yml` (lint conteneur, `doctrine:schema:validate`, tests). Le dépôt
  GitHub est **public** : ne jamais committer de secret, ni de détail d'une faille non déployée.

## Commandes

```bash
cp .env.example .env                      # première installation, puis renseigner les valeurs
composer install                          # lance aussi cache:clear + migrations (auto-scripts)
composer test                             # PHPUnit 9.6 (sans Xdebug)
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
  au user ? Utilisé en `security_post_denormalize` sur les `@ApiResource` et via
  `$this->isGranted('OWNER', $x)` dans les contrôleurs custom.
- `src/Events/` — subscribers `KernelEvents::VIEW` (API Platform) : hash du mot de passe et
  email d'activation à l'inscription, user auto-assigné aux `Years`/`Favorites`, unicité du
  maître de stage, suppression en cascade des interventions d'un chirurgien, claims JWT.
- `src/Security/UserChecker.php` — bloque le login si compte non activé (`token` non null) et
  journalise la connexion dans `ConnectionHistory`.
- `src/Controller/` — endpoints custom (JSON manuel). `AdminControllers/` sous `/api/admin/*`
  (ROLE_ADMIN via `access_control`). `ExcelNewVersion.php` (`/api/excel2/{year}`) est l'export
  courant ; `ExcelGeneratorController.php` (`/api/excel/{year}`) est l'ancienne version.
- `public/ExcelTemplate.xlsx` — gabarit du carnet de stage (chargé par chemin relatif).
- Contrôle d'accès : `config/packages/security.yaml` (`access_control` par préfixe d'URL).

## Conventions du code existant

- Code, commentaires, messages d'erreur et commits **en français**.
- Annotations (pas d'attributs PHP 8) : `@Route`, `@ApiResource`, `@ORM`, `@Groups`, `@Assert`.
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
