# Contrat backend

Le front ne dépend que de l'interface `MedClickApi` (`src/api/types.ts`). Deux implémentations :

- `src/api/mock.ts` : démo en mémoire (`VITE_USE_MOCK=true`), qui applique les mêmes règles que le serveur ;
- `src/api/http.ts` : backend MedClick réel (`VITE_USE_MOCK=false`, `VITE_API_URL=/api`). Elle reprend les appels
  du front actuel (`medclick-pwa`). Les routes qui n'existent pas encore renvoient une erreur 501 explicite.

Authentification : `POST /api/login_check` `{ username, password }` → `{ token }` (JWT), puis
`Authorization: Bearer <token>` sur chaque appel. Les claims `firstname`, `lastname`, `email` et `acceptedTerms`
viennent de `JwtCreatedSubscriber`.

## Correspondance des modèles

| Front (`src/types.ts`) | Backend | Remarque |
|---|---|---|
| `Role` `SOLO` / `ASSISTED` / `SECOND` | `Surgeries.position` 1 / 3 / 2 | |
| `Surgery.supervisorId` | `firstHand` si 2e main, `secondHand` si 1re main aidée | id d'un `Surgeons`, stocké en chaîne |
| `Surgery.acte` | `nomenclature` + `name`, `speciality`, `code` copiés | ADR-007 |
| `Acte.orthoType` | `Nomenclature.type` 1 = électif, 2 = traumatologie | |
| `Acte.region` | `Nomenclature.subType` (`knee`, `hip`, `shoulder`…) | l'icône en dépend |
| `TrainingYear` | `Years` (`yearOfFormation` en chaîne côté backend) | |
| `Surgeon.boss` | `Surgeons.boss` | un seul par année |
| `Favorite` | `Favorites` (`shortcut`, `surgery`) | `getMyList` renvoie la clé `shorcut` (faute d'origine) |
| `Consultation` | `Consultations` (`dayPart` morning/afternoon/night, `number` en chaîne) | `moment` n'est pas utilisé |
| `Garde` | `Gardes` (`dateOfStart`, `dateOfEnd`, `number` = patients vus) | |
| `Formation.local` | `Formations.location === 'local'` | sinon `location` = lieu libre |

## Routes existantes utilisées

| Méthode | Route | Appel front |
|---|---|---|
| POST | `/api/login_check` | `login` |
| POST | `/api/users` | `register` (champs du groupe `user_write`) |
| POST | `/api/forgottenPassword` | `forgotPassword` (`{ username }`, réponse identique que l'adresse existe ou non) |
| POST | `/api/resetPassword` | `resetPassword` (`{ email, token, password }` ; 4xx → « lien expiré ») |
| GET / PUT | `/api/terms-conditions`, `/api/acceptTerms` | `getTerms`, `acceptTerms` |
| GET / PUT | `/api/marketing/active`, `/api/marketing/incrementCampaign/{id}` | `getCampaign`, `registerCampaignClick` |
| GET | `/api/users` | profil (spécialité) |
| GET / PUT | `/api/years`, `/api/years/{id}` ; POST `/api/years/create` | années |
| GET | `/api/excel2/{year}` | `exportLogbook` (téléchargement `.xlsx`) |
| GET | `/api/list/{year}` ; POST / PUT / DELETE `/api/surgeons` | chirurgiens |
| GET | `/api/nomenclature/{speciality}` | `searchNomenclature` (filtres type, région et texte côté client) |
| GET / POST / PUT / DELETE | `/api/favorites/getMyList`, `/addNew`, `/updateNew`, `/api/favorites/{id}` | favoris |
| GET / PUT / DELETE | `/api/surgeries`, `/api/surgeries/{id}` | interventions (PUT = processeur qui resynchronise la nomenclature) |
| GET / POST / PUT | `/api/consultations`, `/api/gardes`, `/api/formations` | listes et formulaires |

## Routes à créer

| Méthode | Route | Pour | Notes |
|---|---|---|---|
| **POST** | **`/api/surgeries/batch`** | quantité × N, journée opératoire | transaction unique, voir plus bas. Le front ne fait **pas** de boucle de POST non atomique. |
| GET | `/api/partner/active` | logo et textes du sponsor | **public** (écran de connexion). 204 ou `null` s'il n'y a pas de partenaire ou hors période. |
| GET / PUT / DELETE | `/api/admin/partner` (+ envoi du logo) | administration du partenaire | `ROLE_ADMIN`. Nom, logo, accroche, texte, URL, 4 engagements, début, fin facultative. |
| GET | `/api/dashboard` | accueil | statistiques de l'année en cours, semaine, série, célébrations en attente |
| GET | `/api/weeks/current?offset=` | Ma semaine | un jour est « complété » s'il porte au moins une activité |
| GET | `/api/days/{date}` | vue du jour | interventions, consultations par `dayPart`, garde |
| GET | `/api/statistics/me` | statistiques | mensuel + répartition par spécialité (électif / traumato séparés) |
| GET / POST | `/api/milestones`, `/api/milestones/celebrated` | milestones | voir plus bas |
| POST | `/api/me/streak/ack` | série de semaines | |
| GET | `/api/nomenclature/item/{id}` | détail d'un acte | facultatif : les interventions et favoris portent déjà une copie de l'acte |

Les objectifs du carnet (`completionTarget`, objectifs par activité dans Progression) n'ont pas de source :
il faut un référentiel par année et par spécialité avant de les afficher en production.

## POST `/api/surgeries/batch`

```json
{
  "common": { "date": "2026-10-04", "yearId": "12", "supervisorId": "57" },
  "lines": [
    { "acteId": "3021", "quantity": 3, "roles": ["SOLO", "ASSISTED", "ASSISTED"] },
    { "acteId": "4410", "quantity": 2, "roles": ["SECOND", "SECOND"] }
  ]
}
```

Règles :
1. Une ligne `Surgeries` **par unité** (ici 5), chacune modifiable et supprimable seule.
2. **Atomique** : tout est validé, puis écrit dans une seule transaction (`wrapInTransaction`). En cas d'erreur, rien n'est créé.
3. Validation : `1 ≤ quantity ≤ 20`, `roles.length === quantity`, nomenclature existante, date non future,
   année appartenant à l'utilisateur (`OWNER`), superviseur obligatoire dès qu'un rôle n'est pas `SOLO`
   et appartenant à cette année. `position`, `firstHand`, `secondHand`, `name`, `speciality`, `code`
   sont calculés comme dans `NewSurgeriesAPIController`.
4. Réponse : `{ created, before, after, newlyAchieved }`. Le front ne lance l'animation de succès qu'après cette réponse.

## Milestones — célébrer une seule fois (§37.6)

Table `user_milestone` : `user_id`, `milestone_id`, `achieved_at`, `celebrated_at` (nullable).
Quand une écriture fait franchir un seuil : `achieved_at = now()`, `celebrated_at = NULL`. `GET /api/dashboard`
renvoie `pendingCelebrations` ; le front montre la célébration puis appelle `POST /api/milestones/celebrated`.
Ne jamais déduire « à célébrer » de `valeur >= seuil`.

## Évolutions (§37.15)

`YearStats.deltas.*` vaut `null` quand la comparaison n'est pas calculable proprement : le front n'affiche alors aucun badge.
