# MedClick backend — Documentation technique

> État au 30/09/2026, établi par lecture du code (branche `master`, commit `cf10252`).

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
SPA (medclick.be, repo séparé)
        │  HTTPS + JWT (Authorization: Bearer)
        ▼
Symfony 5.1 ── API Platform 2.5 (CRUD générique /api/{resource})
   │         └─ Contrôleurs custom (/api/..., /activation/{token})
   │         └─ Subscribers KernelEvents::VIEW (logique pré-écriture)
   ▼
Doctrine ORM ── MySQL
   │
   ├─ PhpSpreadsheet → export .xlsx
   └─ Symfony Mailer (Gmail) → emails Twig (activation, reset mot de passe)
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
| GET | `/api/marketing/active` | public | Campagne active aléatoire (+1 vue) |
| GET | `/api/nomenclature/{speciality}` | user | Nomenclature d'une spécialité |
| GET | `/api/list/{id}` | propriétaire | Chirurgiens d'une année |
| POST | `/api/years/create` | user | Création d'année (unicité année/user) |
| POST | `/api/surgeries/add` | user | Création d'intervention (v1) |
| POST | `/api/surgeries/addNewSurgery` | user | Création d'intervention (v2, `createdAt`) |
| PUT | `/api/surgeries/update/{id}` | user | Mise à jour d'intervention |
| GET | `/api/favorites/getMyList` | user | Favoris du user |
| POST | `/api/favorites/addNew` | user | Ajout favori |
| PUT | `/api/favorites/updateNew` | user | Modification favori |
| GET | `/api/excel/{year}` | propriétaire | Export Excel (ancienne version) |
| GET | `/api/excel2/{year}` | propriétaire | Export Excel (version courante) |
| GET | `/api/statistics/fetch/{userId}` | soi / admin | Statistiques |
| POST | `/api/statistics/update/{userId}` | admin | Recalcul des statistiques |
| GET | `/api/userStat/{id}` | soi / admin | Ids des années d'un user |
| GET | `/api/admin/users` | admin | Liste des utilisateurs + connexions |
| GET | `/api/admin/fetchUserById/{id}` | admin | Profil + années d'un user |
| GET | `/api/admin/history/quick` | admin | Tableau de bord des connexions |
| GET/POST/PUT | `/api/admin/nomenclature…` | admin | CRUD nomenclature, type, sous-type |
| GET/POST/PUT/DELETE | `/api/admin/marketing…` | admin | CRUD campagnes, statut |

Tout `/api` non listé comme public exige un JWT (dernière règle de `access_control`).
« propriétaire » = contrôle `isGranted('OWNER', ...)` via `OwnershipVoter`.

## 6. Flux « ajouter une intervention » (v2)

1. Le front charge la nomenclature (`/api/nomenclature/{speciality}`) et les favoris.
2. `POST /api/surgeries/addNewSurgery` `{year, surgeryId, date, position, firstHand?, secondHand?}`.
3. Le contrôleur copie nom/spécialité/code depuis `Nomenclature`, positionne
   `firstHand`/`secondHand` selon `position`, fixe `createdAt`, persiste.

## 7. Export Excel (`ExcelNewVersion`)

Charge `ExcelTemplate.xlsx` (chemin relatif au répertoire courant = `public/`), vérifie que
l'année appartient au user, puis remplit : page de garde, carnet (superviseurs triés maître
de stage d'abord), interventions triées, récapitulatifs par spécialité (ortho, traumato,
dig, uro, vasc, plast), consultations par mois, gardes, formations. Réponse en
`StreamedResponse` .xlsx.

## 8. Configuration et environnements

Variables : `APP_ENV`, `APP_SECRET`, `DATABASE_URL`, `CORS_ALLOW_ORIGIN`, `JWT_SECRET_KEY`,
`JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, `MAILER_DSN`, `API_URL`.
Clés JWT dans `config/jwt/` (gitignorées). `Procfile` présent (Heroku) mais la base de
production pointe vers un hébergement mutualisé : la cible de déploiement réelle est à
documenter.

## 9. Commandes console

- `app:update-surgeries` : relie les interventions sans nomenclature (par nom, puis préfixe
  de code à 6 caractères), par lots de 40 000.
- `app:update-favorites` : idem pour les favoris.
