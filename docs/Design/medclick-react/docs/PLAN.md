# MedClick — plan des pages

Mobile d'abord (largeur de référence 390 px, coque max 480 px). Identité : Plus Jakarta Sans,
bleu `#2F5BEA`, dégradé de boutons `#4A7BF7 → #2F55E4`, encre `#0B1F4D`, croix translucide bleu/turquoise.
Tous les jetons sont dans `src/styles/tokens.css`. Modèle de données aligné sur le backend : `src/types.ts`,
correspondance champ par champ dans `docs/API.md`.

## Règles métier reprises du backend et du front actuel

- **Rôles** (`Surgeries.position`) : 1re main solo (1), 1re main aidée (3), 2e main (2). Couleurs : vert, bleu, violet.
- **Superviseur selon le rôle** : 1re main aidée → « Aidé par » (`secondHand`) ; 2e main → « 1re main » (`firstHand`) ;
  solo → aucun. L'enregistrement est bloqué tant que le superviseur demandé n'est pas choisi.
- **Nomenclature** : spécialité ; en orthopédie, électif ou traumatologie (`type`) et région (`subType`).
  L'icône d'un acte suit la région, sinon la spécialité (`acteIcon` dans `components/ui.tsx`).
- **Consultations** : nombre de patients par demi-journée (`dayPart` matin / après-midi / nuit). Les interventions
  n'ont pas de demi-journée : la vue du jour les liste sans moment.
- **Jour « complété »** : au moins une activité (intervention, consultation ou garde) encodée ce jour-là.
- **Partenaire** : une seule source (`hooks/usePartner.tsx`). Sans partenaire, chaque emplacement se replie (voir plus bas).
- **Chirurgien supprimé** : ses interventions le sont aussi (règle `SurgeonRemoveProcessor`), confirmation explicite.

## Responsive

| Palier | Largeur | Navigation | Mise en page |
|---|---|---|---|
| Mobile | < 768 px | barre d'onglets en bas, bouton flottant « + » | une colonne |
| Tablette | 768–1199 px | rail à gauche (icônes + libellés courts, bouton « + ») | grilles élargies, formulaires sur une colonne |
| Ordinateur | ≥ 1200 px | barre latérale complète (marque, « Ajouter », liens, export, partenaire) | `split` : contenu principal + panneau latéral |

Tout est dans `src/styles/responsive.css`. Connexion : écran scindé (photo à gauche) dès 1024 px.
Écrans de compte (inscription, mot de passe) : colonne centrée de 480 px sur grand écran.

## Carte des routes

| Route | Fichier | Rôle |
|---|---|---|
| `/connexion` | `LoginPage` | Accueil marque + partenaire (ou message MedClick sans partenaire), feuille de connexion |
| `/inscription` | `RegisterPage` | Inscription, puis « Activez votre compte » |
| `/mot-de-passe-oublie` | `ForgotPasswordPage` | Demande de lien, puis « Vérifiez vos e-mails » |
| `/reinitialiser/:token?email=` | `ResetPasswordPage` | Nouveau mot de passe ; états « modifié » et « lien expiré » |
| `/cgu` | `TermsPage` | Lecture des conditions d'utilisation |
| `/` | `HomePage` | Tableau de bord de l'année et de la semaine, bouton « + » |
| `/ajouter` | `AddChoicePage` | Choix : intervention, journée opératoire, consultation, garde, formation |
| `/activites` | `InterventionsPage` | Onglet Activités › Interventions (filtre par spécialité) |
| `/activites/consultations` · `/gardes` · `/formations` | `*ListPage` | Listes groupées par mois |
| `/interventions/ajouter` | `AddInterventionPage` | Une intervention, quantité 1…20 (§7 BIS) |
| `/interventions/journee` | `OperatingSessionPage` | Journée opératoire multi-actes (§7 TER) |
| `/interventions/:id` | `InterventionDetailPage` | Modifier / supprimer une intervention |
| `/nomenclature` (`?pour=favori`) | `NomenclaturePage` | Recherche INAMI, étoile = favori |
| `/consultations/ajouter` · `/:id` | `ConsultationFormPage` | Patients, moment, spécialité, date, année |
| `/gardes/ajouter` · `/:id` | `GardeFormPage` | Préréglages nuit / jour / 24 h, début, fin, patients vus |
| `/formations/ajouter` · `/:id` | `FormationFormPage` | Type, sujet, début, fin, lieu, rôle, description |
| `/semaine` | `WeekPage` | Complétude de la semaine, jours sans activité |
| `/jour/:date` | `DayPage` | Interventions, consultations par moment, garde |
| `/progression` | `ProgressionPage` | Carnet, progression par activité (dont formations), accès à l'export |
| `/progression/milestones` | `MilestonesPage` | Badges obtenus / à venir |
| `/progression/statistiques` | `StatisticsPage` | KPI, histogramme mensuel, répartition par spécialité |
| `/profil` | `ProfilePage` | Identité, année en cours, menu, déconnexion |
| `/annees` · `/annees/:id` (`nouvelle`) | `YearsPage`, `YearFormPage` | Années de formation et **export Excel** par année |
| `/chirurgiens` · `/chirurgiens/:id` (`nouveau`) | `SurgeonsPage`, `SurgeonFormPage` | Superviseurs, maître de stage unique |
| `/favoris` | `FavoritesPage` | Renommer, retirer, ajouter depuis la nomenclature |
| `/partenaires` | `PartnersPage` | Page sponsor ; redirige vers l'accueil s'il n'y a pas de partenaire |
| `/admin/partenaire` | `AdminPartnerPage` | Administration du partenaire (à reprendre dans `medclick-admin`) |
| `/profil/informations`, `/aide`, `/a-propos`, `/confidentialite` | `InfoPage` | Contenu à rédiger |

Au niveau de l'application (`layouts/AppLayout.tsx`) :
- **Publicité plein écran** (`CampaignInterstitial`) juste après la connexion, s'il y a une campagne active ;
- **Acceptation des CGU** (`TermsGate`) tant que la dernière version n'est pas acceptée.

Navigation : `TabBar` (Accueil, Activités, Ma semaine, Progression, Plus). Les écrans de saisie et de détail
sont plein écran (`AppLayout tabs={false}`). Transition : glissement de 14 px + fondu, 240 ms, inversé au retour.

## Emplacements du partenaire

| Emplacement | Avec partenaire | Sans partenaire |
|---|---|---|
| Connexion | carte « Avec le soutien de » + logo | la carte reste avec « Votre carnet de stage numérique » |
| Accueil | carte « Partenaire du mois » | supprimée |
| Barre latérale (tablette, ordinateur) | encadré « Avec le soutien de » | supprimé |
| Profil | lien « nos partenaires » | supprimé |
| `/partenaires` | page complète | redirection vers l'accueil |

## Animations

Inchangées : voir `docs/MOTION.md` (§37). Le succès ne s'anime qu'après la réponse du serveur ; un milestone
n'est célébré qu'une fois ; les graphiques sont statiques après leur apparition ; le sponsor n'apparaît qu'en fondu.

## Composants partagés

| Composant | Où | Rôle |
|---|---|---|
| `Icon` | `components/Icon.tsx` | Icônes au trait 24×24, dont régions anatomiques et spécialités |
| `Logo`, `Wordmark`, `SponsorMark`, `DecoCross` | `components/ui.tsx` | Marque ; `SponsorMark` lit le partenaire (ou un aperçu) |
| `TopBar`, `TabBar`, `ActivityTabs`, `Skeleton`, `ErrorState` | `components/ui.tsx` | Structure |
| `RoleDot`, `ActeIcon`, `acteIcon`, `CodeBadge`, `DeltaBadge`, `Checkbox`, `Switch` | `components/ui.tsx` | Métier et formulaires |
| `CommonFieldsForm` | `components/` | Date, superviseur (selon le rôle), année |
| `YearSelect`, `DateQuick` | `components/YearSelect.tsx` | Année de formation, date avec raccourcis |
| `SaveResult` | `components/` | Confirmation d'enregistrement + impact + milestone |
| `StatusHero`, `InfoCard` | `components/StatusHero.tsx` | États des parcours de compte |
| `TermsGate`, `CampaignInterstitial` | `components/` | CGU, publicité après connexion |
| `RolePills` | `pages/AddInterventionPage.tsx` | Choix du rôle |
| `PasswordRules` | `pages/RegisterPage.tsx` | Règles 6–50 caractères et confirmation |
| Primitives d'animation | `motion/` | Voir `docs/MOTION.md` |
