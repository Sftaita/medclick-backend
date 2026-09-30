# Audit — Faire de MedClick une application professionnelle

> Réalisé le 30/09/2026 par lecture du code. Chaque constat renvoie au fichier concerné.
> Priorités : **P0** = à corriger avant toute nouvelle fonctionnalité (sécurité / perte de
> données), **P1** = trimestre en cours, **P2** = industrialisation.

## Synthèse

Le métier est solide et bien compris (carnet de stage, nomenclature, export officiel), mais
l'application a les caractéristiques d'un projet personnel ayant grandi : sécurité
d'autorisation incomplète, framework hors support, aucun test, logique dans des
contrôleurs de 1 500 lignes. Les données manipulées (activité clinique nominative de
médecins, noms de chirurgiens) exigent un niveau de rigueur « données sensibles ».

---

## P0 — Sécurité et intégrité (à traiter immédiatement)

> **Suivi (30/09/2026)** — Points 0, 1 et 2 **corrigés**, couverts par `tests/Api/OwnershipTest.php`
> (15 tests ; 9 d'entre eux échouent sur le code d'origine). Voter `src/Security/Voter/OwnershipVoter.php`.

### 0. Modification locale non commitée qui casse `User` — ✅ corrigé
`src/Entity/User.php` (working tree) supprime le constructeur qui initialisait `$years` et
`$favorites` en `ArrayCollection`. Pour un utilisateur nouvellement créé, `getYears()` /
`getFavorites()` (type de retour `Collection`) renverront `null` ⇒ `TypeError`.
**Action** : restaurer le constructeur avant de committer.

### 1. Élévation de privilèges à l'inscription — ✅ corrigé
`User` est un `@ApiResource` sans `denormalizationContext` : `POST /api/users` (anonyme)
accepte `"roles": ["ROLE_ADMIN"]`, ainsi que `token`, `validatedAt`, `resetToken`.
`PUT /api/users/{id}` sur soi-même permet la même chose et enregistre le mot de passe
**en clair** (le hash n'est fait que sur POST — `PasswordEncoderSubcriber`).
**Action** : groupes `user_write` limités (ADR-102), `plainPassword` non mappé, hash sur
POST/PUT/PATCH, `security="is_granted('ROLE_ADMIN') or object == user"` sur les opérations.

### 2. Écriture dans les données d'un autre utilisateur (IDOR) — ✅ corrigé
- ~~API Platform : un IRI `year` d'un autre user est accepté~~ — **constat erroné**, infirmé par
  les tests : la résolution des IRI passe par `CurrentUserExtension`, un IRI étranger renvoie 400
  « Item not found ». Un `security_post_denormalize` (Voter `OWNER`) a tout de même été ajouté
  en seconde barrière.
- `Statistics` était inscriptible par n'importe quel user (POST/PUT/PATCH/DELETE) : réservé
  désormais à `ROLE_ADMIN`.
- `NewSurgeriesAPIController::addNewSurgery` et `SurgeriesController::addSurgery/updateSurgery`
  ne vérifient pas que `$data['year']` appartient au user.
- `FavoritesController::updateFavorite` : aucune vérification de propriétaire.
- `NewSurgeriesAPIController` : `handleMissingData()` est appelé sans `return` ⇒ l'exécution
  continue et plante sur `null`.
**Action** : Voter `OwnershipVoter` + `security_post_denormalize` (ADR-101) ; tests fonctionnels.

### 3. Fuite de données
- `GET /api/excel/{year}` (ancienne version, `ExcelGeneratorController`) ne vérifie pas le
  propriétaire de l'année : tout utilisateur connecté peut exporter le carnet complet
  d'un autre (nom, email, interventions).
- `GET /api/list/{id}` et `/api/nomenclature/{speciality}` ne sont couverts par aucun
  `access_control` ⇒ accessibles sans authentification (liste des chirurgiens d'une année).
- `GET /api/userStat/{id}` et `/api/statistics/fetch/{id}` : pas de contrôle de propriétaire ;
  `fetch` sérialise l'entité avec `ObjectNormalizer` (risque d'exposer `User`, hash inclus).
**Action** : retirer ou sécuriser l'ancien export, ajouter une règle
`^/api` ⇒ `IS_AUTHENTICATED_FULLY` en dernière ligne de `access_control` (liste blanche
des routes publiques au-dessus), vérifier la propriété dans chaque contrôleur.

### 4. Flux mot de passe / activation
- `ForgottenPasswordController::forgottenPassword` : appelle `$user->getFirstname()` avant
  le `if ($user)` ⇒ erreur 500 pour un email inconnu (et énumération des comptes) ; le
  contrôleur ne retourne pas de `Response`.
- `resetPassword` se termine par `dd("Ca fonctionne")` ; le token n'expire jamais ; aucune
  règle de complexité côté reset.
- Token d'activation `md5(uniqid())` : prévisible. Utiliser `random_bytes`.
- `src/Security/PasswordReseter.php` : contrôleur mort (route GET, `dd($e)`), à supprimer ;
  `TokenAuthenticator.php` : squelette vide, à supprimer.
- Pas de limitation de débit sur `/api/login_check`, `/api/forgottenPassword`, `/api/users`.
**Action** : SymfonyCasts `reset-password-bundle`, `symfony/rate-limiter` (login throttling),
réponse identique que l'email existe ou non.

### 5. Journal de connexion faussé
`UserChecker::checkPreAuth` enregistre la connexion **avant** la vérification du mot de
passe : chaque tentative échouée compte comme une connexion dans les statistiques admin.
**Action** : écouter `LoginSuccessEvent` / `AuthenticationSuccessEvent` à la place.

### 6. Secrets et configuration
- `.env` local contient, en commentaire, des identifiants de base de production en clair.
  Il n'est pas versionné, mais doit être nettoyé et les identifiants **changés**.
- `APP_ENV=dev` / `APP_DEBUG=true` dans `.env` : vérifier que la prod tourne bien avec
  `APP_ENV=prod` (le profiler/`dd()` exposent des données sinon).
- Gérer les secrets via `bin/console secrets:set` ou les variables de l'hébergeur.

### 7. Bugs fonctionnels relevés
- `SurgeriesController::updateSurgery` : `$surgery` utilisé avant le test `if(!$surgery)` ;
  en `position == 3`, `secondHand` reçoit `$data['firstHand']` (au lieu de `secondHand`).
- `StatisticsController::update` : `setConsultations(5)` résiduel (écrasé ensuite, mais à retirer).
- `ExcelNewVersion::ExcelGenerator2` : `$searchedYear->getUser()` plante si l'année n'existe
  pas (avant le contrôle).

---

### 8. Outillage cassé en local (constaté pendant les correctifs)
- Les commandes `doctrine:schema:*` plantent (`ConnectionHelper` introuvable) : DBAL 3.9 est
  installé avec DoctrineBundle 2.3, incompatibles.
- `composer.lock` n'est pas installable en PHP 8.2 (`laminas/laminas-code 4.7.1` exige
  PHP < 8.2) alors que le dev tourne en 8.2 : la version PHP de prod est à vérifier.
- `cache:clear` exige une connexion MySQL (pas de `server_version` dans `doctrine.yaml`).
- `simple-phpunit` échoue sous Windows (le pont n'est pas lié) ; contournement documenté
  dans `CLAUDE.md`.

## P1 — Socle technique

| Sujet | Constat | Action |
|---|---|---|
| Framework | Symfony 5.1 (fin de support 01/2021), API Platform 2.5, `guard` déprécié | Montée 5.4 → 6.4 LTS, API Platform 3, nouveau système d'authentification (ADR-103) |
| PHP | `composer.json` : `>=7.2.5` ; dev en 8.2 | Fixer `>=8.2`, attributs PHP 8, types stricts, Rector |
| Tests | `tests/` vide | PHPUnit + `ApiTestCase` : auth, isolation multi-user, export Excel (golden file) ; viser les chemins P0 d'abord |
| Migrations | 2 migrations pour 15 entités | Baseline + `doctrine:schema:validate` en CI (ADR-105) |
| Architecture | Contrôleurs de 1 300–1 500 lignes, logique dupliquée v1/v2 | `src/Service/` : `SurgeryFactory`, `LogbookExporter` (une classe par feuille), suppression de l'ancien export (ADR-104) |
| Validation | `json_decode` + accès direct à `$data['x']` (notices, 500) | DTO + `#[MapRequestPayload]` (Symfony 6.3+) ou Validator |
| Erreurs | Mélange `die`, `dd`, `\Exception`, JSON `{message}` / `{error}` | Format unique (RFC 7807 `application/problem+json`), `ExceptionListener` |
| CORS | En-tête `Access-Control-Allow-Origin: $_ENV[...]` ajouté à la main (valeur = une regex) | Laisser `nelmio_cors` gérer, supprimer ces en-têtes |
| Emails | Envoi synchrone via Gmail | Messenger (async) + fournisseur transactionnel (Brevo, Postmark…) avec SPF/DKIM sur `medclick.be` |
| Chemin gabarit | `load('ExcelTemplate.xlsx')` relatif au CWD | `%kernel.project_dir%/resources/ExcelTemplate.xlsx` |
| Typage modèle | `yearOfFormation` string, `firstHand`/`secondHand` = id en string, `counter` string | Enum/int, relations Doctrine explicites (User / Surgeons) |
| Nettoyage | `MailerController` exposé en route `/mail`, `AppController`, webpack encore inutilisé, `Procfile` Heroku | `MailerController` → service `Mailer`, retirer ce qui n'est pas utilisé |

## P2 — Industrialisation

- **CI/CD** (GitHub Actions) : `composer validate`, PHPStan (niveau 6+), PHP-CS-Fixer,
  tests, `composer audit`, puis déploiement automatisé (Deployer ou pipeline hébergeur).
- **Environnements** : dev / staging / prod séparés ; Docker Compose (PHP-FPM, MySQL, Mailpit)
  pour ne plus dépendre de WAMP.
- **Observabilité** : Monolog JSON en prod, Sentry pour les erreurs, health-check `/health`,
  monitoring d'uptime.
- **Base de données** : sauvegardes automatiques chiffrées + test de restauration, index sur
  `surgeries.year_id`, `years.user_id`, `connection_history(user_id, date)`.
- **API** : versionnement (`/api/v2`), pagination partout, doc OpenAPI incluant les
  endpoints custom, refresh tokens JWT (`gesdinet/jwt-refresh-token-bundle`) et TTL court.
- **RGPD** (ADR-106) : export/suppression de compte en libre-service, politique de
  rétention (`ConnectionHistory`, `MarketingView`), consentement marketing distinct des CGU,
  journal d'audit des consultations admin, DPA avec l'hébergeur (UE).
- **Produit** : rôle « maître de stage » pouvant valider/signer le carnet, export PDF signé,
  multi-langue (NL pour la Flandre), import/export CSV, tableau de bord de progression
  par rapport aux quotas de formation.

## Plan proposé

1. **Semaine 1** — P0 n°0 à 5 + tests fonctionnels couvrant l'isolation entre deux users.
2. **Semaines 2–3** — Nettoyage (code mort, `dd`, CORS manuel), baseline des migrations, CI
   avec PHPStan et tests.
3. **Mois 2** — Montée Symfony 5.4 puis 6.4, API Platform 3, extraction des services Excel.
4. **Mois 3** — Observabilité, sauvegardes, RGPD, retrait des endpoints v1.
