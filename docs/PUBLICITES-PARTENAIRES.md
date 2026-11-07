# Publicités & partenaires — administration par emplacement

*Refonte du 31/10/2026. Branches `feature/refonte-publicites-partenaires` (backend et frontend),
créées depuis `master` / `main` (MedClick 3.5.1). Rien n'est fusionné ni déployé.*

Ce document remplace, pour l'administration, les deux systèmes décrits dans `docs/SPONSORS.md`
(campagnes) et `docs/PARTENAIRES.md` (firmes par emplacement). Les mécanismes de diffusion et de
mesure qu'ils décrivent restent ceux du code ; les différences sont listées en section 4.

Captures : `docs/refonte-publicites-partenaires/` (base de démonstration isolée, entreprises et
visuels fictifs).

---

## 1. Audit : emplacements réellement présents

Tous les emplacements demandés existaient déjà dans le code ; aucun parcours n'a été inventé.

| # | Emplacement (intitulé de l'administration) | Où dans le code | Contenu | Avant la refonte |
|---|---|---|---|---|
| 1 | **Page d'identification — Publicité** | `auth/pages/LoginPage.jsx` | publicités (placement `login`), 3 au plus | ✅ ; l'encart partenaire `login_fallback` occupe la même place quand aucune publicité n'est servie |
| 2 | **Après connexion — Publicité plein écran** | `features/ads/AdOverlay.jsx`, `LoginPage.jsx` | publicité (placement `login_interstitial`) | ⚠️ affichée **pendant** l'authentification, sans bouton pour fermer (3.5.1), tirage pondéré, fréquence fixe (1/jour) |
| 3 | **Tableau de bord — Carrousel publicitaire** | `features/dashboard/DashboardPage.jsx` | publicités `dashboard_desktop` / `dashboard_mobile`, 3 au plus | ✅ ; au-delà de 3, tirage au sort à chaque chargement |
| 4 | **Tableau de bord — Partenaire du mois** | `PartnerSlots.jsx` (`PartnerOfTheMonthCard`) | entreprise `dashboard_partner` | ⚠️ **masqué dès qu'une publicité du carrousel était servie** (simple repli) |
| 5 | **Navigation ordinateur — Barre latérale** | `app/AppShell.jsx` (`SidebarPartner`) | entreprise `sidebar` | ⚠️ **masquée quand une publicité « exclusive » était à l'écran** (`features/ads/exclusivity.js`) |
| 6 | **Navigation mobile — Menu** | `AppShell.jsx` (`MobileMenuPartner`) | entreprise `mobile_menu` | ✅ |
| 7 | **Accueil public — Sponsor** | `features/home/components/OriginSection.jsx` | entreprise `public_home` | ✅ |
| 8 | **Page « Nos partenaires »** | `features/partner/PartnersPage.jsx` (`/partenaires`) | entreprises `partners_page` (plusieurs) | ⚠️ **aucun lien de navigation** : atteinte seulement par les encarts |

Autres constats :
- **Une entreprise et une publicité étaient confondues** : les publicités portaient un simple texte
  `sponsor_name`, sans lien avec les fiches d'entreprise (`partner`) ; une même marque existait
  deux fois, sans rapport entre ses publicités et ses encarts.
- **Trois administrations** (Publicités, Partenaires, Gestion avancée) et un bouton « Remplacer
  partout » parmi les actions courantes.
- **Aucun brouillon pour les publicités** : toute modification était en ligne immédiatement.
- **Bouton « Découvrir » invisible sur mobile** (correctif demandé le 31/10) : il n'existait que si
  l'option « Afficher le bouton » était cochée, et dans un pied trop étroit (mention, logo de 132 px,
  texte et bouton sur 358 px) le bouton pouvait être rogné.

## 2. Structure cible

### Vocabulaire

| Terme | Table | Rôle |
|---|---|---|
| **Entreprise** | `partner` | Fiche unique : nom, logo, site, description, engagements, visuel, contact administratif. |
| **Publicité** | `marketing` (+ `partner_id`) | Contenu de communication d'**une** entreprise, sur un ou plusieurs emplacements compatibles. |
| **Attribution** | `partner_placement_assignment` | Une entreprise présentée sur un emplacement « entreprise » pour une période. |
| **Emplacement** | `App\Advertising\Emplacements` + `ad_placement_setting` | Un des huit endroits réels ; réglages (activé, fréquence, ordre). |

### Administration (frontend `admin/publicites/`)

`/admin/publicites` — trois onglets :
- **Emplacements** (`EmplacementsPage`) : huit cartes regroupées par écran (Connexion, Tableau de
  bord, Navigation, Pages publiques), chacune avec son croquis de position, ce qui est en ligne et
  jusqu'à quand, la suite programmée, les modifications à publier et les conflits.
- **Un emplacement** (`EmplacementPage`, `/admin/publicites/emplacements/:key`) : description,
  activation, fréquence (plein écran), contenus (entreprise, statut, dates, statistiques, actions
  Modifier / Remplacer / Programmer / Désactiver / Supprimer), ordre par glisser-déposer ou
  flèches, calendrier sur six mois (périodes libres, conflits ; un clic choisit le jour de
  l'aperçu), **aperçu fidèle** « Actuellement en ligne » / « Mes modifications », ordinateur /
  mobile, à une date choisie, agrandissable. Barre « Publier les modifications » / « Abandonner ».
- **Entreprises** (`CompaniesPage`) : bibliothèque, fiche avec contact, emplacements utilisés,
  contenus en ligne / programmés / terminés ; « Remplacer partout… » uniquement ici, avec la liste
  détaillée des emplacements concernés avant confirmation.
- **Statistiques** (`StatsPage`) : impressions, clics, taux de clics, évolution quotidienne, par
  emplacement, par entreprise, par contenu ; période et filtres.

Les anciennes pages (`admin/ads`, `admin/marketing`, `admin/partners`, `admin/communication`)
sont supprimées ; leurs adresses redirigent vers les nouveaux onglets.

### API d'administration (ROLE_ADMIN)

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/admin/emplacements` | Les huit emplacements (résumé) |
| GET | `/api/admin/emplacements/{key}?date=` | Contenus, aperçus en ligne / modifications, calendrier, conflits, dimensions recommandées |
| PUT | `/api/admin/emplacements/{key}/settings` | `{ enabled, frequency }` — **immédiat** |
| POST | `/api/admin/emplacements/{key}/contents` | Nouveau contenu en brouillon (`type: ad` ou `partner`) |
| PUT / DELETE | `/api/admin/emplacements/{key}/contents/{type}/{id}` | Modifier (brouillon, ou modification en attente) / supprimer un brouillon |
| POST | `…/contents/{type}/{id}/{deactivate\|reactivate\|discard}` | Désactiver (**immédiat**) / réactiver (en attente) / annuler la modification |
| PUT | `/api/admin/emplacements/{key}/order` | Nouvel ordre (en attente) |
| POST | `/api/admin/emplacements/{key}/publish` | Publie tout l'emplacement ; 409 `{ message, conflicts }` ; `replace: true` pour remplacer une entreprise en place |
| POST | `/api/admin/emplacements/{key}/discard` | Abandonne les modifications en attente |
| GET / POST / PUT / DELETE | `/api/admin/companies[/{id}]` | Bibliothèque des entreprises (suppression : seulement sans aucun contenu) |
| POST | `/api/admin/companies/{id}/replace-everywhere` | Sans `confirm` : liste des emplacements ; avec : remplacement publié |
| GET | `/api/admin/ad-stats?from&to&emplacement&company` | Statistiques réunies, ventilées par placement |

Les routes publiques ne changent pas de forme (`/api/marketing/slots`, `/active`, `/events`,
`/api/partner/placements`, `/events`) ; `/api/marketing/active` renvoie en plus `frequency` et
`sponsor`.

Les anciennes routes d'administration (`/api/admin/marketing`, `/partner-firms`,
`/partner-placements`, `/partners`, `/marketing-stats`) sont **conservées** pour le retour arrière
du frontend ; plus aucun écran ne les appelle. À retirer après la mise en production.

## 3. Règles de diffusion

Code : `App\Advertising\Emplacements` (catalogue), `App\Service\EmplacementManager` (brouillons,
publication, conflits, calendrier), `SponsorDelivery` (publicités), `PartnerPlacementManager`
(entreprises).

1. **Brouillon d'abord.** Rien de ce qui est enregistré dans un emplacement ne change l'affichage
   public avant « Publier » : nouveau contenu = brouillon ; modification d'un contenu publié =
   `pending_changes` ; nouvel ordre = `pending_order`. La diffusion ne lit jamais ces colonnes.
   Exceptions, immédiates par nature et confirmées : **Désactiver** un contenu, **activer /
   désactiver** un emplacement, **fréquence** du plein écran.
2. **Dates.** Jours inclus, heure de Belgique ; sans date de fin possible. Le début et la fin sont
   automatiques (calcul à chaque requête, aucune tâche planifiée).
3. **Capacité, sans tirage au sort.** Page d'identification et carrousel : 3 publicités ; plein
   écran : 1 ; emplacements d'entreprise : 1 ; page « Nos partenaires » : plusieurs. Ordre : celui
   de l'administration. Une publicité « seule dans le carrousel » occupe toutes les places.
   **La publication refuse** un dépassement ou un chevauchement (409, avec dates et noms) : ce qui
   est affiché est toujours prévisible. Le carrousel est contrôlé séparément pour l'ordinateur et
   pour le mobile.
4. **Emplacements indépendants.** Le « Partenaire du mois » s'affiche **avec** le carrousel ; la
   barre latérale n'est plus masquée par une publicité exclusive. Sans publicité ni Partenaire du
   mois, une carte MedClick garde la place du carrousel (pas de saut de mise en page).
5. **Seule règle entre deux contenus, documentée et affichée dans l'administration** : sur la page
   d'identification, l'encart partenaire et la publicité occupent la **même place** ; l'encart
   n'apparaît que lorsqu'aucune publicité de cette page n'est en ligne (décision validée le 31/10).
6. **Plein écran après connexion.** Demandé **après** une connexion réussie, avec le jeton (plus
   pendant l'authentification). Boutons « Continuer vers MedClick » (ferme, aussi Échap) et
   « Découvrir {entreprise} » (site dans un nouvel onglet, clic mesuré, la publicité reste
   ouverte), distincts ; fermeture automatique après la durée choisie. **Deux zones** : le visuel,
   affiché en entier dans tout l'espace disponible (jamais recadré ni recouvert ; bandes floutées
   si l'écran est plus étroit que le visuel), puis une barre distincte avec la mention
   « Publicité », le compte à rebours et les deux boutons (pleine largeur et empilés sur téléphone,
   au-dessus des zones système ; une ligne sur ordinateur). Fréquence par navigateur : à chaque connexion,
   une fois par jour (valeur reprise de l'existant), une fois par semaine. Sans publicité,
   emplacement désactivé, serveur lent (> 3 s) ou en erreur : tableau de bord directement.
7. **Bouton « Découvrir ».** Présent dès qu'un lien est configuré, sur chaque diapositive, vrai
   lien (`<a target="_blank" rel="noopener noreferrer sponsored">`), clic mesuré ; jamais de
   bouton inactif. Sur un petit écran, le texte puis le logo s'effacent, jamais le bouton (40 px de
   haut, ne rétrécit pas). Les encarts partenaires restent des liens entiers vers la fiche.
8. **Visuels.** Formulaires : un visuel ordinateur et un visuel mobile, **réutilisation explicite**
   (« Utiliser le même visuel… »). Dimensions affichées = rendus réels : page d'identification et
   carrousel 1600 × 900 (16:9) et 1200 × 600 (2:1) ; plein écran 1920 × 1080 (16:9) et
   1080 × 1920 (9:16). Les anciens cinq formats du plein écran restent lus (campagnes existantes) ;
   une modification depuis la nouvelle administration les remplit à partir des deux visuels.
9. **Mesure.** Inchangée (50 % visible pendant 1 s, dédoublonnage 30 min, aucune donnée
   nominative). Les aperçus de l'administration ne déclenchent aucun événement. Les statistiques
   sont toujours ventilées par placement (carrousel ordinateur, carrousel mobile, encart
   partenaire… jamais additionnés entre eux dans une même ligne).
10. **Lien « Nos partenaires ».** Barre latérale (ordinateur et tablette) et menu mobile, dès
    qu'au moins une entreprise est présentée sur la page.

## 4. Migration des données (`Version20261109090000` (écrite `Version20261105090000`, renommée à l'intégration), additive)

- `ad_placement_setting` : une ligne par emplacement, tous **activés**, plein écran « une fois par
  jour » (comportement actuel), ordre vide (= priorité puis ancienneté, l'ordre actuel).
- `marketing.partner_id` : chaque publicité est rattachée à l'entreprise du même nom que son
  annonceur (casse et espaces ignorés) ; une entreprise est créée, **une seule par nom**, pour un
  annonceur sans fiche ; plusieurs fiches du même nom (anciennes reprogrammations) : la plus
  ancienne. Publicités sans annonceur : sans entreprise (à compléter dans l'administration).
- `marketing.pending_changes`, `partner_placement_assignment.pending_changes`,
  `partner.contact_*` : nouvelles colonnes vides.
- Rien n'est supprimé : campagnes, visuels, logos, attributions, historiques, `marketing_view`,
  `partner_event`, compteurs historiques.

**Collations de la production (MariaDB 11.8).** `marketing` y est en `utf8mb4_unicode_ci` et
`partner` en `utf8mb4_uca1400_ai_ci` (tables créées à des époques différentes) : comparer leurs noms
sans collation explicite échoue (erreur 1267). La migration compare donc les noms en
`utf8mb4_unicode_ci` des deux côtés. Attention : MariaDB n'annule pas les instructions de structure
déjà exécutées quand une migration échoue en cours de route ; en cas d'échec, restaurer la
sauvegarde plutôt que relancer.

**Vérifié** sur une MariaDB 11.8 jetable reproduisant exactement ces collations (et sur MySQL 8.3) :
la version sans collation explicite échoue comme elle l'aurait fait en production ; la version
corrigée rattache « Arthrex » et « arthrex » (espaces) à une seule entreprise créée, « bosi » à la
plus ancienne des deux fiches « BOSI », « Léa Médical » (accents) à sa nouvelle fiche, laisse la
publicité sans annonceur sans entreprise, ne modifie rien d'autre ; descente puis remontée sans
doublon.

**Publicités qui resteront sans entreprise.** Sur la **copie de la production** du 01/11/2026
(sauvegarde automatique de 03:50, répétition de la section 9) : **les cinq publicités** n'ont pas
d'annonceur renseigné, donc aucune n'est rattachée par la migration, et aucune entreprise n'est
créée :

| id | Publicité | Statut | Emplacements | Lien |
|---|---|---|---|---|
| 1 | Test Arthrex | inactive (janvier 2025) | plein écran | — |
| 2 | MedVue Presentation 1600x900 | active | carrousel du tableau de bord | aucun |
| 3 | Indisponibilites Mobile | active | carrousel du tableau de bord | aucun |
| 4 | Planning Ordinateur | active | carrousel du tableau de bord | medvue.be |
| 5 | MedVue Campagne Desktop 1600x900 | inactive | page d'identification, plein écran | — |

Entreprises existantes : BOSI (#1) et « Medvue » (#2). Les publicités 2 à 5 semblent être celles de
Medvue, mais un rattachement d'après le titre reste une supposition : **à confirmer**. « Test
Arthrex » reste à examiner. Les publicités « BOSI Knee Academy » (11 à 13) n'existent **qu'en
préproduction** (créées pendant la recette 3.5.x) : la commande de rattachement les concerne là,
pas en production.

Rattachement : commande `app:attach-campaigns-to-company`, en **simulation par défaut** (liste des
publicités, entreprise actuelle, effet visible), écriture seulement avec `--apply`, garde-fou
`--expect-name`, retour arrière `--detach`. Effet visible : le pied du carrousel affiche le nom et
le logo de l'entreprise ; visuels, liens, dates, ordre et statistiques inchangés. Répété sur la
copie de la production (publicités 2 à 5 vers « Medvue ») : simulation, application, affichage
vérifié, retour arrière.

```
# Préproduction (BOSI Knee Academy) ou production (Medvue), après confirmation :
php bin/console app:attach-campaigns-to-company --campaign=11 --campaign=12 --campaign=13 --company=BOSI --expect-name="Knee Academy"          # simulation
php bin/console app:attach-campaigns-to-company --campaign=11 --campaign=12 --campaign=13 --company=BOSI --expect-name="Knee Academy" --apply  # écriture
php bin/console app:attach-campaigns-to-company --campaign=11 --campaign=12 --campaign=13 --company=BOSI --detach --apply                       # retour arrière
```

**Écart de schéma préexistant.** En production, `doctrine:schema:validate` signale un index
`wdbi_name_191_…` sur `nomenclature`, créé hors Doctrine. Il est présent **avant** la migration et
identique **après** : la refonte n'ajoute aucun écart.

Retour arrière : `doctrine:migrations:migrate prev` (les entreprises créées pour les annonceurs
restent, sans effet sur la version précédente).

Comportements qui **changent** pour l'existant (voulu) :
- au-delà de 3 publicités simultanées (aucune en production le 29/10), plus de tirage au sort :
  les 3 premières dans l'ordre ; l'administration signale le conflit ;
- plusieurs plein écrans simultanés : un seul affiché (le premier dans l'ordre) ;
- le bouton « Découvrir » apparaît pour toute publicité ayant un lien.

## 5. Tests

Backend (`tests/Api/EmplacementAdminTest.php`, 20 tests) : huit emplacements ; droits ; brouillon
invisible jusqu'à publication ; modification en attente, abandon, publication ; désactivation
immédiate et réactivation ; **indépendance des deux emplacements de connexion** (activation,
fréquence) ; encart partenaire de la page d'identification ; **carrousels ordinateur et mobile**
(support seul, réutilisation, visuel manquant refusé) ; ordre en attente puis publié ; **capacité,
exclusivité et chevauchements** ; **publicités et partenaires affichés ensemble** ; remplacement
d'une entreprise exclusive ; page partagée ; périodes libres ; entreprises sans doublon et contact
jamais public ; « remplacer partout » ; **statistiques séparées par emplacement et entreprise,
aperçus non comptés** ; **campagne existante conservée**. `RouteIntegrityTest` couvre les nouvelles
routes. Les 142 tests existants des publicités et partenaires passent sans modification.
`AttachCampaignsToCompanyCommandTest.php` (5 tests) : simulation sans écriture, application
idempotente, garde-fous (nom attendu, entreprise ambiguë, publicité d'une autre entreprise),
retour arrière. Export CSV des statistiques : couvert dans `EmplacementAdminTest`.

Correspondance des 88 tests frontend supprimés avec la nouvelle administration :
`frontend/docs/publicites-couverture-tests.md` (dépôt frontend).

Frontend : `admin/publicites/Publicites.test.jsx` (cartes, aperçu sans mesure, création, ordre,
conflit puis remplacement, désactivation, entreprises, statistiques) ; `AdOverlay.test.jsx` et
`LoginPage.test.jsx` réécrits pour le plein écran après connexion ; `SponsorCarousel.test.jsx`
(bouton « Découvrir » par diapositive, absent sans lien, jamais rétréci) ; `campaign.test.js`
(fréquences) ; `partner.test.jsx` (barre latérale et Partenaire du mois non masqués) ;
`AppShell.test.jsx` (lien « Nos partenaires »).

## 6. Décisions et points ouverts

Décisions validées le 31/10/2026 : emplacements de connexion indépendants ; un seul plein écran à
la fois ; carrousel de 3 publicités dans l'ordre configuré ; Partenaire du mois indépendant du
carrousel ; barre latérale jamais masquée ; sur la page d'identification, la publicité en ligne
remplace l'encart partenaire ; « Nos partenaires » dans la navigation ; bibliothèque d'entreprises
sans doublon.

Restent ouverts :
1. **Recette sur téléphones réels** (iPhone, Android, portrait) : vérifié en émulation (Chromium
   « Pixel 7 », WebKit « iPhone 13 ») sur l'environnement de démonstration.
2. **Plein écran après la connexion** au lieu de pendant : remplace volontairement le comportement
   de 3.5.1.
3. **Anciennes routes d'administration** à retirer après la mise en production (section 2).
4. **Publicités sans entreprise** (section 4) : les publicités 2 à 5 de la production vers
   « Medvue », et les trois « BOSI Knee Academy » de la préproduction vers BOSI, **sur
   confirmation** ; « Test Arthrex » à examiner.

## 7. Parcours de recette (environnement de démonstration)

*(La procédure de mise en production est en section 9.)*

Données fictives : MedVue, BOSI, OrthoNova, CardioPlus ; un administrateur et deux résidents
fictifs. Liens des annonceurs : `https://example.com/...`. Téléphone : en portrait.

**Côté utilisateur (téléphone puis ordinateur), sans être connecté**
1. *Page d'identification — Publicité* : publicité MedVue sous le formulaire ; « Découvrir »
   entier et visible, ouvre `example.com/medvue` dans un nouvel onglet.
2. *Accueil public — Sponsor* : page d'accueil, encart « Application sponsorisée par BOSI ».
3. *Page « Nos partenaires »* : depuis l'encart de l'accueil public ; trois entreprises.

**Connexion avec un résident**
4. *Après connexion — Publicité plein écran* : OrthoNova en plein écran (réglée « à chaque
   connexion ») ; « Découvrir OrthoNova » ouvre le site sans fermer la publicité ;
   « Continuer vers MedClick » mène au tableau de bord.
5. *Tableau de bord — Carrousel publicitaire* : MedVue et CardioPlus ; sur chaque diapositive,
   « Découvrir » ouvre le site de cette publicité.
6. *Tableau de bord — Partenaire du mois* : BOSI, affiché en même temps que le carrousel.
7. *Navigation mobile — Menu* (☰) : encart BOSI et lien « Nos partenaires ».
8. *Navigation ordinateur — Barre latérale* (ordinateur ou tablette) : « Avec le soutien de BOSI »,
   présent même avec le carrousel, et lien « Nos partenaires ».

**Connexion avec l'administrateur : Administration › Publicités & partenaires**
9. *Emplacements* : huit cartes ; le carrousel signale « 1 modification à publier » (brouillon
   BOSI). L'ouvrir : comparer « Actuellement en ligne » et « Mes modifications », ordinateur et
   mobile ; choisir un jour dans le calendrier (après le 10 : OrthoNova apparaît).
10. Publier le brouillon BOSI, puis le vérifier sur le tableau de bord d'un résident.
11. Ajouter au carrousel une publicité avec des dates qui chevauchent les trois autres (au-delà du
    10e jour) : le conflit s'affiche, « Publier » est refusé ; la supprimer.
12. Réordonner (flèches ou glisser-déposer), publier, vérifier l'ordre côté résident.
13. *Entreprises* : créer une entreprise avec son logo ; *Page d'identification* : lui ajouter une
    publicité (deux visuels importés), constater qu'elle n'est pas en ligne, publier, vérifier
    côté visiteur.
14. Modifier une publicité en ligne (lien) : l'ancien lien reste en ligne jusqu'à la publication.
    Puis « Désactiver maintenant » : retirée aussitôt.
15. *Après connexion* : désactiver l'emplacement → connexion directe au tableau de bord, page
    d'identification inchangée ; changer la fréquence.
16. *Statistiques* : les clics des étapes 1, 4 et 5 apparaissent sur leurs emplacements ;
    filtres par emplacement et par entreprise.

## 8. Captures (base de démonstration, entreprises et visuels fictifs)

Chromium, ordinateur 1440 × 900, mobile 390 × 844 (émulation).

**Administration**

| | |
|---|---|
| Emplacements par écran ![](refonte-publicites-partenaires/01-admin-emplacements.jpg) | Carrousel, « Mes modifications » ![](refonte-publicites-partenaires/02-admin-carrousel-mes-modifications.jpg) |
| Aperçu agrandi ![](refonte-publicites-partenaires/02b-admin-carrousel-apercu-agrandi.jpg) | « Actuellement en ligne » ![](refonte-publicites-partenaires/03-admin-carrousel-en-ligne.jpg) |
| Aperçu mobile ![](refonte-publicites-partenaires/04-admin-carrousel-apercu-mobile.jpg) | Ajouter une publicité ![](refonte-publicites-partenaires/05-admin-ajouter-publicite.jpg) |
| Page d'identification ![](refonte-publicites-partenaires/06-admin-page-identification.jpg) | Après connexion ![](refonte-publicites-partenaires/07-admin-apres-connexion.jpg) |
| Partenaire du mois et calendrier ![](refonte-publicites-partenaires/08-admin-partenaire-du-mois-calendrier.jpg) | Entreprises ![](refonte-publicites-partenaires/09-admin-entreprises.jpg) |
| Fiche entreprise ![](refonte-publicites-partenaires/10-admin-fiche-entreprise.jpg) | Statistiques ![](refonte-publicites-partenaires/11-admin-statistiques.jpg) |
| Mobile : emplacements ![](refonte-publicites-partenaires/12-admin-mobile-emplacements.jpg) | Mobile : un emplacement ![](refonte-publicites-partenaires/13-admin-mobile-carrousel.jpg) |

**Rendus utilisateurs**

| Ordinateur | Mobile |
|---|---|
| ![](refonte-publicites-partenaires/20-connexion-ordinateur.jpg) | ![](refonte-publicites-partenaires/20-connexion-mobile.jpg) |
| ![](refonte-publicites-partenaires/21-apres-connexion-plein-ecran-ordinateur.jpg) | ![](refonte-publicites-partenaires/21-apres-connexion-plein-ecran-mobile.jpg) |
| ![](refonte-publicites-partenaires/22-tableau-de-bord-ordinateur.jpg) | ![](refonte-publicites-partenaires/22-tableau-de-bord-mobile.jpg) |
| ![](refonte-publicites-partenaires/24-nos-partenaires-ordinateur.jpg) | ![](refonte-publicites-partenaires/23-menu-mobile.jpg) |
| ![](refonte-publicites-partenaires/25-accueil-public-sponsor.jpg) | ![](refonte-publicites-partenaires/24-nos-partenaires-mobile.jpg) |

## 9. Mise en production et restauration (répétées sur une copie de la production)

### Répétition (à refaire la veille de la mise en production)

`deploy/vps/rehearse-migration.sh <sauvegarde> <image actuelle> <image nouvelle> <rapport>` restaure
la dernière sauvegarde chiffrée de la production dans une MariaDB jetable (réseau interne, sans port,
fichiers de travail supprimés à la fin) et enchaîne : empreinte de toutes les tables, affichage
public avec la version actuelle, sauvegarde « avant », migration, contrôles, affichage avec la
nouvelle version, rattachement (simulation, application, retour arrière), puis les deux retours
arrière. Le rapport ne contient que des comptages, empreintes et données publiques.

Résultat du 01/11/2026 (sauvegarde `medclick-prod-20261101T035002Z`, images `f5d13db` → `0827ca2`) :

| Étape | Résultat | Durée |
|---|---|---|
| Restauration de la copie (25 tables, 266 comptes, 5 publicités, 2 entreprises, 10 attributions) | OK | 18 s |
| Migration `Version20261109090000` (écrite `Version20261105090000`, renommée à l'intégration) | OK ; 8 réglages, 0 entreprise créée, aucun doublon | **2 s** |
| Affichage public après migration (publicités et entreprises par emplacement) | **identique** à l'actuel | — |
| Retour arrière A : descente de la migration | OK ; affichage de la version actuelle identique ; **toutes les tables identiques** à l'empreinte d'avant | 2 s |
| Retour arrière B : restauration de la sauvegarde « avant » dans une base neuve | **empreinte identique** pour toutes les tables | 14 s |

### Procédure (après accord, fenêtre calme)

1. **Fusion** : PR backend puis PR frontend ; noter les SHA `B` et `F`.
2. **Sauvegarde** (VPS, `/opt/stack/apps/medclick-prod`) :
   `./backup.sh /opt/stack/apps/medclick-prod prod` (chiffrée, contrôlée, copiée hors VPS) et
   `cp -a src/pwa src/pwa.<sha actuel>` (sources du frontend en service).
3. **Répétition** sur cette sauvegarde : `rehearse-migration.sh` (section ci-dessus) ; arrêter si
   une étape n'est pas « OK » ou si l'affichage diffère.
4. **Déploiement** : livrer `src/pwa` (`git archive F frontend`), puis `./deploy.sh B F` ; il s'arrête
   sur « 1 migration(s) en attente » ; exécuter
   `docker compose exec -T backend php bin/console doctrine:migrations:migrate --no-interaction`,
   puis relancer `./deploy.sh B F`.
5. **Contrôles (5 minutes)** : `doctrine:migrations:status` sans migration en attente ;
   `GET /api/marketing/slots` et `GET /api/partner/placements` : mêmes publicités et entreprises
   qu'avant ; administration › Publicités & partenaires : huit emplacements, aucun conflit ;
   connexion avec un compte d'essai : plein écran (s'il y en a un), tableau de bord.
6. **Rattachements confirmés** : `app:attach-campaigns-to-company` en simulation, puis `--apply`.

### Retour arrière

- **Sans écriture dans la nouvelle administration** : `doctrine:migrations:migrate prev
  --no-interaction`, puis `./deploy.sh <SHA précédents>` (répété : tables identiques, affichage
  identique).
- **Si la migration échoue en cours de route, ou après des écritures** : `./restore.sh into
  /opt/stack/apps/medclick-prod <sauvegarde de l'étape 2>`, puis `./deploy.sh <SHA précédents>`
  (répété : restauration complète en 14 s sur la copie, empreinte identique).
- Frontend : `rm -rf src/pwa && cp -a src/pwa.<sha> src/pwa`.
