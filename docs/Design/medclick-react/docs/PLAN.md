# MedClick — plan des pages

Mobile d'abord (largeur de référence 390 px, coque max 480 px). Identité : Plus Jakarta Sans,
bleu `#2F5BEA`, dégradé de boutons `#4A7BF7 → #2F55E4`, encre `#0B1F4D`, croix translucide bleu/turquoise.
Tous les jetons sont dans `src/styles/tokens.css`.

## Responsive

| Palier | Largeur | Navigation | Mise en page |
|---|---|---|---|
| Mobile | < 768 px | barre d'onglets en bas, bouton flottant « + » | une colonne |
| Tablette | 768–1199 px | rail à gauche (icônes + libellés courts, bouton « + ») | grilles élargies (KPI ×4, actes ×2, jours côte à côte), formulaires sur une colonne |
| Ordinateur | ≥ 1200 px | barre latérale complète (marque, « Ajouter », liens, partenaire) | `split` : contenu principal + panneau latéral (sticky pour les formulaires) |

Tout est dans `src/styles/responsive.css`. Classes utilitaires neutres sur mobile : `split`, `split-main`,
`split-side`, `split--even`, `split-side--sticky`, `kpi-grid`, `tiles-4`, `r-2`, `r-3`, `acte-grid`,
`narrow`, `hide-mobile`, `only-mobile`. Connexion : écran scindé (photo à gauche) dès 1024 px.
Sur tablette et ordinateur, la barre d'action du bas devient une rangée alignée à droite.

| Écran | Tablette | Ordinateur |
|---|---|---|
| Accueil | KPI sur 4 colonnes | « Mon année » à gauche, semaine + partenaire à droite |
| Interventions | recherche et filtres sur une ligne, colonnes chirurgien / année | idem dans une carte, sans bouton flottant |
| Ajouter | actes sur 2 colonnes | actes à gauche, quantité + détails + rôle dans un panneau sticky à droite |
| Journée opératoire | une colonne | infos communes / actes en deux colonnes égales |
| Détail | formulaire centré 760 px, champs sur 2 colonnes | idem |
| Ma semaine | tuiles ×4 | résumé + anneau à gauche, jours + alerte à droite |
| Vue du jour | matin et après-midi côte à côte | idem |
| Progression | une colonne | carnet à gauche, activités à droite |
| Milestones | grille ×2 | grille ×3 |
| Statistiques | KPI ×4 | histogramme et donut côte à côte |
| Profil | une colonne | identité à gauche, menu à droite |

## Carte des routes

| Route | Fichier | Barre d'onglets | Rôle |
|---|---|---|---|
| `/connexion` | `LoginPage` | non | Accueil marque + sponsor, puis feuille de connexion |
| `/` | `HomePage` | Accueil | Tableau de bord de l'année et de la semaine |
| `/interventions` | `InterventionsPage` | Interventions | Liste, recherche, filtres par région |
| `/interventions/ajouter` | `AddInterventionPage` | non | Encodage d'un acte, quantité 1…20 (§7 BIS) |
| `/interventions/journee` | `OperatingSessionPage` | non | Journée opératoire multi-actes (§7 TER) |
| `/interventions/:id` | `InterventionDetailPage` | non | Modifier / supprimer une intervention |
| `/semaine` | `WeekPage` | Ma semaine | Complétude de la semaine, jours à compléter |
| `/jour/:date` | `DayPage` | non | Matin / après-midi / garde d'un jour |
| `/progression` | `ProgressionPage` | Progression | Carnet global + progression par activité |
| `/progression/milestones` | `MilestonesPage` | Progression | Badges obtenus / à venir |
| `/progression/statistiques` | `StatisticsPage` | Progression | KPI, histogramme mensuel, répartition |
| `/profil` | `ProfilePage` | Plus | Identité, paramètres, déconnexion |
| `/partenaires` | `PartnersPage` | non | Page sponsor (OrthoNova) |

Navigation : `TabBar` (5 onglets) dans `AppLayout`. Les écrans de saisie et de détail sont plein écran
(`AppLayout tabs={false}`). Transition : glissement de 14 px + fondu, 240 ms, inversé au retour.

---

## 1. Connexion — `/connexion`
- Logo croix 80 px, wordmark « MedClick », accroche, titre « Connexion ».
- Carte « Avec le soutien de OrthoNova », qui chevauche la photo du bloc.
- Photo pleine largeur fondue en haut, vague claire en bas, halo turquoise à droite.
- « Continuer » ouvre une feuille (e-mail, mot de passe) → `api.login` → `/`.
- À faire : SSO hospitalier si nécessaire, mot de passe oublié.

## 2. Accueil — `/`
Données : `api.getDashboard()` → `Dashboard`.
- En-tête : logo, wordmark, cloche (pastille non-lus), avatar → `/profil`.
- « Bonsoir {prénom} ».
- **Carte « Mon année »** (2×2) : interventions, première main, consultations, % carnet (anneau). Chaque
  tuile a son badge d'évolution (`DeltaBadge`, masqué si la valeur est `null`). Bandeau « N semaines à jour » → `/semaine`.
- **Cette semaine** : 3 tuiles (encodées, 1re main, jours non complétés) + bouton « Compléter ma semaine ».
- **Partenaire du mois** → `/partenaires`.
- Animations : skeleton → compteurs 124 / 47 → anneau 72 % + impulsion → évolutions décalées de 200 ms →
  série. Si `pendingCelebrations` n'est pas vide : carte milestone flottante ~300 ms après, puis
  `markMilestonesCelebrated` (une seule fois). Si `streak.weeks > acknowledgedWeeks` : compteur 5 → 6 et
  feu animé 500 ms, puis `acknowledgeStreak`.

## 3. Mes interventions — `/interventions`
Données : `api.listSurgeries({ region, q })`.
- Recherche, puces Toutes / Genou / Hanche / Épaule.
- Liste : icône d'acte, pastille de rôle (vert 1re main, violet assistance, gris observation), date relative.
- Après un enregistrement : bandeau de succès et lignes nouvelles surlignées (`location.state.createdIds`).
- FAB « + » → `/interventions/ajouter`.

## 4. Ajouter une intervention — `/interventions/ajouter` (§7 BIS)
- Segmenté « Une intervention | Journée opératoire ».
- Favoris (`getFavoriteActeIds`) + « Tous les actes ».
- **Quantité** : `CountStepper` (1 par défaut, max 20). Le bouton devient « Enregistrer N interventions ».
- Quantité > 1 → « Personnaliser les N interventions » (facultatif) : un sélecteur de rôle par intervention.
- Détails communs : rôle (pastilles), date (Aujourd'hui / Hier / Avant-hier / calendrier),
  chirurgien, année, nomenclature. `?date=AAAA-MM-JJ` pré-remplit la date (lien depuis `/jour/:date`).
- Enregistrer → `api.createBatch` (une ligne, N rôles). Bouton « Enregistrement… » pendant l'appel.
- Succès (après réponse serveur uniquement) : `SaveResult` → ✓ dessiné, « 3 LCA enregistrées »,
  carte « Votre activité » 121 → 124 et 44 → 47, puis milestone si `newlyAchieved` n'est pas vide.
  Actions disponibles immédiatement : « Ajouter une autre intervention », « Voir les interventions ».
- Échec : message, formulaire conservé, rien n'est créé (transaction).

## 5. Journée opératoire — `/interventions/journee` (§7 TER)
- Carte « Commun à toute la session » : date, chirurgien, année.
- Lignes : acte + `CountStepper` + rôle (segmenté) + supprimer (repli neutre `Collapsible`).
- Sélecteur « + acte » sous forme de puces (actes non encore ajoutés).
- Bouton : « Enregistrer les 6 interventions » (« Ajoutez un acte » si vide) ; mention « tout ou rien ».
- Succès : même `SaveResult` (118 → 124, 41 → 47, milestone éventuel).

## 6. Détail d'une intervention — `/interventions/:id`
- Carte acte + étoile favori (animation scale/rotation 260 ms).
- Date, rôle, chirurgien senior, année, nomenclature, notes (compteur /500).
- « Enregistrer l'intervention » → ✓ bref en vert.
- « Supprimer » → confirmation en ligne → suppression → retour à la liste avec message. Les autres
  interventions du même lot ne sont pas touchées.

## 7. Ma semaine — `/semaine`
Données : `api.getWeek(offset)`.
- Navigation de semaine, 4 tuiles (interventions, 1re main, consultations, gardes).
- Ordre d'apparition : résumé → anneau de complétude + message → jours (décalés) → problème restant →
  bouton « Compléter maintenant » → `/jour/:date`.
- Passage à 100 % (complétude déjà vue < 100, mémorisée par semaine) : l'anneau termine depuis 94,
  impulsion, « ✓ Semaine complète », série 6 → 7 avec feu animé.

## 8. Vue du jour — `/jour/:date`
- Onglets « Vue du jour | Mes notes ».
- Matin / Après-midi : état (✓ encodé ou « Non complété »), liste, bouton « + » → ajout pré-daté.
- Garde : « Aucune garde — en ajouter une ».

## 9. Progression — `/progression`
- Onglets Vue d'ensemble / Milestones / Statistiques (`ProgressionTabs`).
- Carte carnet : anneau, « 124 / 172 interventions validées », barre.
- Par type d'activité : 4 barres animées (objectifs à charger depuis le référentiel de formation).

## 10. Milestones — `/progression/milestones`
- « 5 / 12 milestones validés » + barre ; filtres Tous / À venir / Validés.
- Validés : fond vert pâle, ✓, date d'obtention. À venir : progression chiffrée (ex. 18 / 20).
- Aucune célébration ici : la surprise n'a lieu qu'au franchissement (§37.6).

## 11. Statistiques — `/progression/statistiques`
- Période, 4 KPI avec compteur puis évolution 200 ms plus tard (« = stable » si 0, rien si `null`).
- Histogramme mensuel (barres qui montent, décalage 55 ms), donut par région (tracé progressif).
- Une fois affichés, les graphiques sont statiques.

## 12. Profil — `/profil`
- Avatar, nom, e-mail, année + hôpital. Menu : informations, préférences, notifications,
  synchronisation (« ✓ À jour »), aide, à propos, CGU, confidentialité. Lien partenaires, déconnexion.

## 13. Partenaires — `/partenaires`
- Bandeau marque, onglets À propos / Ressources, 4 engagements, « Découvrir OrthoNova ↗ ».
- Animations sponsor : simple fondu, jamais répété (§37.20).

---

## Composants partagés

| Composant | Où | Rôle |
|---|---|---|
| `Icon` | `components/Icon.tsx` | Icônes au trait 24×24 |
| `Logo`, `Wordmark`, `PartnerMark`, `DecoCross` | `components/ui.tsx` | Marque |
| `TopBar`, `TabBar`, `Skeleton`, `ErrorState` | `components/ui.tsx` | Structure |
| `RoleDot`, `ActeIcon`, `DeltaBadge` | `components/ui.tsx` | Métier |
| `CommonFieldsForm` | `components/` | Date / chirurgien / année / nomenclature communs |
| `SaveResult` | `components/` | Confirmation d'enregistrement + impact + milestone |
| `RolePills` | `pages/AddInterventionPage.tsx` | Choix du rôle |
| `ProgressionTabs` | `pages/ProgressionPage.tsx` | Sous-navigation progression |
| Primitives d'animation | `motion/` | Voir `docs/MOTION.md` |
