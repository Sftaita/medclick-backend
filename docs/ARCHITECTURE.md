# MedClick backend — Documentation technique

> Établi le 30/09/2026 par lecture du code (commit `cf10252`) ; mis à jour pour MedClick 3.2.0
> (28/10/2026) : pile technique, exports Excel, hébergement. Décisions : `docs/DECISIONS.md`.

## 1. Le produit

MedClick est le carnet de stage numérique des **médecins assistants en chirurgie** (Belgique).
Pour chaque **année de formation**, le résident consigne :

| Élément | Entité | Exemple |
|---|---|---|
| Interventions chirurgicales | `Surgeries` | date, intervention (nomenclature INAMI), rôle 1re/2e main, chirurgien |
| Superviseurs / chirurgiens | `Surgeons` | nom, prénom, maître de stage (`boss`, unique par année) |
| Consultations | `Consultations` | date, nombre, moment/partie de journée, spécialité |
| Gardes | `Gardes` | début, fin, nombre |
| Formations | `Formations` | congrès/cours, dates, lieu (`local` ⇒ hôpital de l'année), rôle |

Il génère ensuite le **carnet de stage officiel au format Excel** (page de garde, carnet,
récapitulatifs par spécialité, consultations, gardes, formations) à partir de
`public/ExcelTemplate.xlsx`.

Côté administration : gestion de la nomenclature, liste et profil des utilisateurs,
statistiques de connexion, campagnes marketing (bannières affichées dans l'app).

## 2. Architecture

```
SPA React/Vite (www.medclick.be, dépôt séparé)
        │  HTTPS + JWT (Authorization: Bearer) — Traefik (VPS)
        ▼
Symfony 8.1 / PHP 8.4 (FrankenPHP) ── API Platform 4 (CRUD générique /api/{resource})
   │         └─ Contrôleurs custom (/api/..., /activation/{token})
   │         └─ State processors (src/State/, logique pré-écriture)
   ▼
Doctrine ORM 3 ── MariaDB 11.8 (dédiée)
   │
   ├─ Symfony Messenger (transport Doctrine) → worker : export .xlsx (PhpSpreadsheet), surveillance
   ├─ Gardien (cron de l'hôte) : surveillance du worker, indépendante de lui
   └─ Symfony Mailer (Gmail) → e-mails Twig (activation, reset mot de passe, alertes techniques)
```

Deux styles d'endpoint coexistent :

1. **API Platform** (entités annotées `@ApiResource`) : `User`, `Years`, `Surgeries`,
   `Surgeons`, `Consultations`, `Gardes`, `Formations`, `Favorites`, `Statistics`.
   Opérations CRUD par défaut (GET collection/item, POST, PUT, PATCH, DELETE).
   Lecture filtrée par `CurrentUserExtension`.
2. **Contrôleurs custom** qui construisent le JSON à la main, pour les cas métier
   (création d'intervention à partir de la nomenclature, export Excel, admin…).

## 3. Modèle de données

```
User 1──* Years 1──* Surgeries *──1 Nomenclature
  │          ├──* Surgeons
  │          ├──* Consultations
  │          ├──* Gardes
  │          └──* Formations
  ├──* Favorites *──1 Nomenclature
  ├──1 Statistics (compteurs dénormalisés, recalculés par l'admin)
  └──* ConnectionHistory

TermsConditions (versions des CGU, la plus récente fait foi)
Marketing 1──* MarketingView
Partner *──1 PartnerLogo (logo PNG/SVG en base, adressé par SHA-256)
```

Points notables :

- `User` : `email` (identifiant), `roles` (JSON), `password` (hash), `token` (activation,
  null ⇒ compte actif), `resetToken`, `speciality`, `acceptedTerms` + `termsAcceptedDate`,
  `counter`, `createdAt`/`validatedAt`.
- `Years.yearOfFormation` est une **chaîne** ("1" à "8"…), `master` est un texte libre ; le
  maître de stage « structuré » est le `Surgeons` avec `boss = true`.
- `Surgeries` : `position` (1 = 1re main, 2 = 2e main, 3 = 1re main aidée), `firstHand` /
  `secondHand` = id de user (résident) **ou** id de `Surgeons`, stockés en string ;
  `code` = `Nomenclature.codeHospitalisation . Nomenclature.n` ; `name` et `speciality`
  sont copiés depuis la nomenclature (dénormalisation).
- `Favorites` copie aussi nom/code/spécialité de la nomenclature.
- `Nomenclature` : `speciality`, `codeAmbulant`, `codeHospitalisation`, `n`, `name`,
  `type`, `subType`.

## 4. Authentification et autorisation

- **Inscription** : `POST /api/users` (anonyme). `PasswordEncoderSubcriber` hash le mot de
  passe ; `ActivationTokenEncoder` génère un token et envoie `email/activationEmail.html.twig`.
- **Activation** : `GET /activation/{token}` ⇒ `token = null`, `validatedAt = now`,
  redirection vers `https://www.medclick.be/#/login`.
- **Login** : `POST /api/login_check` `{username, password}` ⇒ JWT. `UserChecker` refuse les
  comptes non activés (`checkPreAuth`) et enregistre une ligne `ConnectionHistory` après un
  login réussi (`checkPostAuth`). `JwtCreatedSubscriber`
  ajoute au payload : `firstname`, `lastname`, `email`, `acceptedTerms`, `termsAcceptedDate`.
- **Mot de passe oublié** : `POST /api/forgottenPassword {username}` ⇒ `resetToken` +
  `resetTokenRequestedAt` + email (réponse identique si l'email est inconnu) ;
  `POST /api/resetPassword {email, token, password}` ⇒ token valable 1 h, usage unique.
- **CGU** : `GET /api/terms-conditions` (public), `PUT /api/acceptTerms` (connecté).
- **Autorisation** : `access_control` par préfixe dans `security.yaml` ;
  `/api/admin/*` ⇒ `ROLE_ADMIN`. Isolation des données : `CurrentUserExtension` (lecture
  API Platform uniquement) + contrôles manuels dans certains contrôleurs custom.

## 5. Endpoints custom

| Méthode | Route | Rôle | Description |
|---|---|---|---|
| POST | `/api/login_check` | public | Login JWT |
| POST | `/api/users` | public | Inscription (API Platform) |
| GET | `/activation/{token}` | public | Activation du compte |
| POST | `/api/forgottenPassword` | public | Demande de reset |
| POST | `/api/resetPassword` | public | Reset du mot de passe |
| GET | `/api/terms-conditions` | public | Dernières CGU |
| PUT | `/api/acceptTerms` | user | Acceptation des CGU |
| GET | `/api/marketing/active` | public | Campagne plein écran aléatoire (lecture seule, docs/SPONSORS.md) |
| GET | `/api/marketing/slots` | public | Campagnes par emplacement (connexion, tableau de bord) |
| POST | `/api/marketing/events` | public, limité | Impression réellement vue ou clic (dédoublonné) |
| GET | `/api/partner/active` | public | Partenaire affiché aujourd'hui (204 sinon) |
| GET | `/api/partner/logo/{sha256}.{png\|svg}` | public | Logo du partenaire (CSP sans script, cache immuable) |
| GET | `/api/marketing/image/{sha256}.webp` | public | Visuel importé d'une campagne (CSP sans script, cache immuable) |
| GET | `/api/nomenclature/{speciality}` | user | Nomenclature d'une spécialité |
| GET | `/api/list/{id}` | propriétaire | Chirurgiens d'une année |
| POST | `/api/years/create` | user | Création d'année (unicité année/user) |
| POST | `/api/surgeries/add` | user | Création d'intervention (v1) |
| POST | `/api/surgeries/addNewSurgery` | user | Création d'intervention (v2, `createdAt`) |
| PUT | `/api/surgeries/update/{id}` | user | Mise à jour d'intervention |
| GET | `/api/favorites/getMyList` | user | Favoris du user |
| POST | `/api/favorites/addNew` | user | Ajout favori |
| PUT | `/api/favorites/updateNew` | user | Modification favori |
| POST/GET | `/api/exports`, `/api/exports/{id}`, `…/download` | propriétaire | **API Excel canonique** : export asynchrone du carnet de stage (docs/EXPORTS.md) |
| GET | `/api/excel2/{year}`, `/api/excel/{year}` | propriétaire | Alias synchrones de compatibilité (même fichier, contrat inchangé) |
| GET | `/api/statistics/fetch/{userId}` | soi / admin | Statistiques |
| POST | `/api/statistics/update/{userId}` | admin | Recalcul des statistiques |
| GET | `/api/userStat/{id}` | soi / admin | Ids des années d'un user |
| GET | `/api/admin/users` | admin | Liste des utilisateurs + connexions |
| GET | `/api/admin/fetchUserById/{id}` | admin | Profil + années d'un user |
| GET | `/api/admin/history/quick` | admin | Tableau de bord des connexions |
| GET/POST/PUT | `/api/admin/nomenclature…` | admin | CRUD nomenclature, type, sous-type |
| GET/POST/PUT/DELETE | `/api/admin/marketing…` | admin | CRUD campagnes, statut, emplacements, exclusivité |
| GET | `/api/admin/marketing-stats`, `….csv` | admin | Statistiques publicitaires, export CSV (docs/SPONSORS.md) |
| POST/DELETE | `/api/admin/marketing-images…` | admin | Import d'un visuel (multipart `file`, PNG/JPEG/WebP 5 Mo) ; suppression d'un import abandonné (409 s'il est utilisé) |
| GET/POST/PUT/DELETE | `/api/admin/partners…` | admin | Partenaire : brouillon, publication, `withdraw`, `reschedule`, historique |
| POST | `/api/admin/partner-logos` | admin | Envoi d'un logo (multipart `file`, PNG ≥ 512 px ou SVG sûr, 1 Mo) |

Partenaire (`src/Service/PartnerManager.php`) : un seul affiché à la fois (chevauchement de
périodes refusé à la publication) ; affiché = publié, date de début atteinte, date de fin non
dépassée, calculé à chaque requête (Europe/Brussels, comparaison de jours) : passage automatique
d'un partenaire au suivant, sans tâche planifiée. Les logos sont stockés en base
(`partner_logo`) : inclus dans les sauvegardes, indépendants des conteneurs.

Visuels des campagnes (`src/Service/MarketingImageProcessor.php`, `MarketingImageStore.php`) :
une image principale (`large_screen_format`) et quatre variantes (smartphone, tablette portrait,
tablette paysage, ordinateur = `screen_14_inch_format`). Chaque champ contient soit l'URL
relative d'un visuel importé (`/api/marketing/image/<sha256>.webp`), soit une URL externe
(anciennes campagnes, conservées et toujours affichées). Import : type vérifié sur le contenu,
480 à 8000 px par côté, 25 Mpx et 5 Mo au plus, images animées refusées ; réencodé en WebP
(métadonnées supprimées, orientation EXIF appliquée, 2560 px et 1,5 Mo au plus) et stocké en
base (`marketing_image`, sauvegardes comprises). Un visuel remplacé, retiré ou dont la campagne
est supprimée est effacé s'il ne sert à aucune autre campagne ; un import jamais enregistré est
supprimé par le front à l'annulation, sinon purgé après 24 h. Une valeur déjà enregistrée
renvoyée inchangée est acceptée telle quelle (ex. images collées tronquées de « Test Arthrex »).

Tout `/api` non listé comme public exige un JWT (dernière règle de `access_control`).
« propriétaire » = contrôle `isGranted('OWNER', ...)` via `OwnershipVoter`.

## 6. Flux « ajouter une intervention » (v2)

1. Le front charge la nomenclature (`/api/nomenclature/{speciality}`) et les favoris.
2. `POST /api/surgeries/addNewSurgery` `{year, surgeryId, date, position, firstHand?, secondHand?}`.
3. Le contrôleur copie nom/spécialité/code depuis `Nomenclature`, positionne
   `firstHand`/`secondHand` selon `position`, fixe `createdAt`, persiste.

## 7. Export Excel (carnet de stage)

Un seul générateur, `src/Export/CarnetDeStage/` (détail : `docs/EXPORTS.md`). Charge
`public/ExcelTemplate.xlsx` (analysé une fois, en cache), vérifie que l'année appartient au user,
puis remplit : page de garde, carnet (superviseurs triés maître
de stage d'abord), interventions triées, récapitulatifs par spécialité (ortho, traumato,
dig, uro, vasc, plast), consultations par mois, gardes, formations. Généré par le worker pour
`/api/exports`, ou directement dans la réponse pour les alias synchrones (`CarnetDeStageExcelController`).

## 8. Configuration et environnements

Variables : `APP_ENV`, `APP_SECRET`, `DATABASE_URL`, `CORS_ALLOW_ORIGIN`, `JWT_SECRET_KEY`,
`JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, `MAILER_DSN`, `API_URL`, alertes (`ERROR_ALERT_EMAIL`,
`EXPORT_ALERT_EMAIL`) et réglages des exports (`EXPORT_*`, `docs/EXPORTS.md`).
Clés JWT dans `config/jwt/` (gitignorées). Hébergement : VPS (Docker Compose, Traefik), production
et préproduction depuis la bascule des 23 et 24/10/2026 : `docs/DEPLOIEMENT-VPS.md` (le `Procfile`
Heroku et `docs/DEPLOIEMENT.md`, Hostinger, sont historiques).

## 9. Commandes console

- `app:update-surgeries` : relie les interventions sans nomenclature (par nom, puis préfixe
  de code à 6 caractères), par lots de 40 000.
- `app:update-favorites` : idem pour les favoris.
