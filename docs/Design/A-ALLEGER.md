# Ce qui n'a plus lieu d'être après le nouveau design

> Établi le 05/10/2026, branche `design/medclick-react-v2`, par lecture du code de la maquette
> (`docs/Design/medclick-react`), du front actuel (`medclick-pwa-lot2`, commit `46912de`) et du backend.
> Planches de référence : canevas « MedClick — écrans de l'application » (claude.ai, privé).
>
> **Règle** (CLAUDE.md, ADR-006) : ne supprimer une route ou un écran du front actuel qu'une fois le nouveau
> front en production et l'ancien retiré. Ce document liste les candidats ; il ne supprime rien.

## 1. Maquette React — déjà retiré ou remplacé sur cette branche

| Avant | Pourquoi | Remplacé par |
|---|---|---|
| Rôles `FIRST_HAND` / `ASSISTANT` / `OBSERVER` | n'existent pas dans le backend | `SOLO` / `ASSISTED` / `SECOND` (= `position` 1 / 3 / 2) |
| Champ « Chirurgien » unique | le backend demande le superviseur selon le rôle | « Aidé par » / « 1re main », masqué en solo |
| `data/actes.ts` (régions genou / hanche / épaule) | catalogue fictif | `data/referentiel.ts` : spécialités, `type`, `subType` de la nomenclature |
| Filtres Genou / Hanche / Épaule, filtre « Traumato » | la traumatologie est un type d'acte orthopédique | filtres par spécialité ; électif / traumato dans la recherche nomenclature |
| Notes d'intervention (compteur /500) | aucun champ dans `Surgeries` | retirées |
| Vue du jour matin / après-midi pour les interventions | seules les consultations ont un `dayPart` | interventions sans moment, consultations par moment |
| Jour « incomplet » (`missing: 'Après-midi : non complété'`) | règle sans base dans les données | jour « sans activité » |
| `PartnerMark` (logo OrthoNova dessiné en dur) | sponsor géré par l'administration | `SponsorMark` + `usePartner` (source unique, repli sans partenaire) |
| `Partner` obligatoire dans `Dashboard` | il peut ne pas y avoir de partenaire | `getPartner(): Partner \| null` |
| Onglet « Interventions », route `/interventions` | consultations, gardes et formations ont aussi leur liste | onglet « Activités » (`/activites`, 4 sous-onglets) ; `/interventions` redirige |
| Profil : « Préférences », « Synchronisation » | rien côté backend | années, chirurgiens, favoris, export Excel, CGU acceptées |
| `yearLabel`, `longDate` (`lib/format.ts`) | plus utilisés | `YEAR_LABEL` (`data/referentiel.ts`) |
| Accroche « DES STAGES AUJOURD'HUI… » | demande du porteur du projet | « Le carnet de stage qui vous suit tout au long de votre formation » |

Toujours présents dans la maquette mais **sans source backend** (à garder comme cible ou à retirer, décision à prendre) :
milestones et célébrations, série de semaines, évolutions en %, objectifs du carnet (`completionTarget`, objectifs
par activité), cloche de notifications. Voir `docs/Design/medclick-react/docs/API.md` › « Routes à créer ».

## 2. Front actuel (`medclick-pwa-lot2`) — à supprimer quand le nouveau front le remplace

### Déjà inutilisés aujourd'hui (aucun import)

`components/AdDisplay.jsx`, `ThemeSetup.jsx`, `molecules/TableCustom.jsx`, `atoms/TitleH1.js`,
`atoms/CustomCheckbox.js`, `atoms/CustomPopover.js`, `atoms/TableLoader.js`, `molecules/IconAlternate.js`.
Ils peuvent être supprimés dès maintenant, indépendamment du nouveau design.

### Appels vers des routes qui n'existent pas dans le backend

| Appel du front | Effet aujourd'hui | Suite |
|---|---|---|
| `PUT /api/marketing/incrementCampaign/{id}` (`marketingAPI.incrementClic`) | 404 : les clics sur la publicité ne sont jamais comptés (`Marketing.clicks` reste à 0) | créer la route, ou retirer l'appel |
| `GET /api/favorites/getById/{id}` (`favoritesAPI`) | 404 | retirer (le nouveau front lit `getMyList`) |

### Remplacés écran par écran

| Front actuel | Nouveau design |
|---|---|
| `LoginPage` (+ publicité inline) | `LoginPage` + `CampaignInterstitial` |
| `RegisterPage`, `RegisterSuccessPage`, `ConditionDialog` | `RegisterPage` (état « Activez votre compte »), `TermsPage` |
| `ForgottenPasswordPage`, `PasswordEmailPage`, `ResetPasswordPage`, `PasswordSuccessPage` | `ForgotPasswordPage`, `ResetPasswordPage` (états modifié / expiré) |
| `termsConditionsDialog`, `TermsConditionsDialogWithoutInteraction` | `TermsGate` |
| `FrontPage` (tuiles de menu) | `HomePage` + `AddChoicePage` |
| `SurgeriesPage`, `surgeriePage/*` (formulaire, modal, data) | `InterventionsPage`, `AddInterventionPage`, `OperatingSessionPage`, `NomenclaturePage`, `InterventionDetailPage` |
| `ConsultationsPage` / `ConsultationPage`, `GardesPage` / `GardePage`, `FormationsPage` / `FormationPage` | `*ListPage` / `*FormPage` |
| `YearsPage` (bouton Excel « ancienne / nouvelle version ») / `YearPage` | `YearsPage` (export `excel2` uniquement) / `YearFormPage` |
| `SurgeonsPage` / `SurgeonPage` (routes `/surgeons/:id` **et** `/surgeon/:id` en double) | `SurgeonsPage` / `SurgeonFormPage` |
| `FavoritesPage` / `FavoritePage` | `FavoritesPage` (renommage en place) |
| route de test `/test/:id` | supprimée |

Avec eux disparaissent la couche Material UI et ses composants maison : `atoms/*` (`CustomSelect`, `CustomSwitch`,
`Dropdown`, `Field`, `Icon`, `Image`, `LearnMoreLink`), `molecules/*` (`CustomButtonGroup`, `CustomDialog`,
`DateHandler`, `DateTimeHandler`, `DeletingDialog`, `Header`, `LoadingDialog`, `Modal`, `SectionHeader`),
`organisms/*` (`Accordion`, `CardBase`, `CardCategory`, `DescriptionListIcon`, `Section`, `SectionAlternate`),
`CustomPagination`, `Navbar`, `SideDrawer`, ainsi que les services axios (`js/Services/*`), remplacés par
`src/api/http.ts`.

**Décision à prendre** : `HomePage` (page d'accueil publique de présentation) n'a pas d'équivalent dans le
nouveau design. À garder telle quelle, à redessiner, ou à remplacer par l'écran de connexion.

**À conserver** : l'administration (`/api/admin/*`, chunks `admin-*`) n'est pas couverte par ce design, sauf la
nouvelle page Partenaire.

## 3. Backend — candidats au retrait après l'arrêt de l'ancien front

Le nouveau front n'appelle pas ces routes. À retirer seulement quand plus aucun front en service ne les utilise
(vérifier `medclick-pwa`, `medclick-pwa-lot2` et `medclick-admin`), dans l'esprit de l'ADR-104.

| Élément | Remplacé par / raison |
|---|---|
| `POST /api/surgeries/add` (v1, `SurgeriesController::addSurgery`) | `addNewSurgery`, puis `POST /api/surgeries/batch` à créer |
| `PUT /api/surgeries/update/{id}` (`SurgeriesController::updateSurgery`) | `PUT /api/surgeries/{id}` (API Platform + `SurgeryNomenclatureProcessor`) |
| `GET /api/excel/{year}` + `ExcelGeneratorController` (~1 300 lignes) | `GET /api/excel2/{year}` (`ExcelNewVersion`) |
| `GET /api/userStat/{id}` | inutile : les années sont listées par `GET /api/years` |
| `GET /api/statistics/fetch/{id}`, `POST /api/statistics/update/{id}`, entité `Statistics` | à remplacer par des statistiques calculées (`/api/dashboard`, `/api/statistics/me`) ; garder tant que l'admin les affiche |
| `Consultations.moment` | jamais lu par l'export ni par les fronts ; `dayPart` suffit (migration à prévoir) |
| `Years.master` (texte libre) **et** `Surgeons.boss` | doublon : le maître de stage structuré est le chirurgien `boss` ; garder un seul des deux |
| `MailerController` (route `/mail`), `AppController` | déjà signalés par l'audit (P1 › Nettoyage) |

## 4. À créer pour que le nouveau design fonctionne sur le vrai backend

Détail et payloads : `docs/Design/medclick-react/docs/API.md` › « Routes à créer ». En résumé :
`POST /api/surgeries/batch` (transactionnel), partenaire (`GET /api/partner/active` public, `/api/admin/partner`),
`/api/dashboard`, `/api/weeks/current`, `/api/days/{date}`, `/api/statistics/me`, milestones et série,
comptage des clics de campagne, référentiel des objectifs du carnet.
