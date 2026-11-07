# Recherche publique des codes INAMI — spécifications fonctionnelles

Statut : design validé le 04/11/2026 ; **implémenté** sur les branches `feature/recherche-inami`
(backend et front), en attente de recette. Détails techniques : `docs/RECHERCHE-INAMI.md`.
Compléments visuels : `README.md` et la prévisualisation.

Écarts d'implémentation par rapport à ce document : recherche, tri et pagination faits par le
serveur (R3, R7) ; import des bannières étroites par `?format=banner` (480 × 120 px au minimum) ;
mesure publicitaire de la page sans aucun stockage navigateur (identifiant en mémoire).

## 1. Objet

Moteur public et gratuit de recherche des codes INAMI pour les professionnels de la santé (notamment
les médecins diplômés sans carnet de stage), alimenté par la nomenclature de MedClick.

## 2. Accès

| Règle | Détail |
|---|---|
| A1 | Page publique `/codes-inami`, URL permanente, accessible directement, sans compte et gratuitement. |
| A2 | Point d'entrée : bouton « Trouver un code INAMI » dans la carte de connexion, sous « Se connecter », après un séparateur « ou ». |
| A3 | Avant la première recherche de la session, la fenêtre « Êtes-vous professionnel de la santé ? » s'affiche. « Oui, je suis professionnel de la santé » donne accès à la recherche. « Non » ferme la fenêtre (retour à la connexion pour un accès direct par l'URL). |
| A4 | La réponse « Oui » est une **simple déclaration**. Ce n'est **ni une authentification ni une vérification** de la qualité de professionnel. Elle n'ouvre aucun droit supplémentaire (voir P1). |
| A5 | La déclaration est retenue jusqu'à la fermeture du navigateur (cookie de session `mc_inami_pro`, partagé par les onglets). La fenêtre n'est pas redemandée pendant la même session. |

## 3. Recherche

| Règle | Détail |
|---|---|
| R1 | Données : **toujours la nomenclature actuelle** de MedClick (table `nomenclature`), lue par une route publique en lecture seule. Jamais de copie statique. Le cache doit rester court pour que les modifications faites dans l'administration apparaissent vite. |
| R2 | Sélecteur de spécialité : seules les spécialités présentes dans la nomenclature, avec les libellés de l'application. La dernière spécialité choisie est retenue 12 mois (cookie `mc_inami_specialite`, identifiant seul). Une spécialité retenue qui n'existe plus est oubliée. |
| R3 | Recherche pendant la frappe, par nom d'intervention ou par numéro de code (ambulatoire ou hospitalisation), sans tenir compte des accents ni de la casse. Avec plusieurs mots, tous doivent être présents. |
| R4 | Résultats sur quatre colonnes, exactement : **Nom · Code ambulatoire · Code hospitalisation · N ou K**. |
| R5 | Copie d'un code en un clic, avec confirmation visuelle (« Copié ») et vocale (lecteur d'écran). |
| R6 | États : premier accès (choix de la spécialité), chargement, erreur avec « Réessayer », aucun résultat avec « Chercher dans toutes les spécialités ». |
| R7 | Smartphone : cartes au lieu du tableau, champ de recherche accroché en haut pendant le défilement, focus sur le champ après le choix d'une spécialité, chargement automatique des résultats suivants. |
| R8 | Date de dernière actualisation : **non affichée** tant qu'aucune date fiable n'existe. La table `nomenclature` n'en contient pas. |
| R9 | Mention en pied de page : codes donnés à titre indicatif ; la nomenclature officielle de l'INAMI fait foi. |
| R10 | Limite de requêtes par adresse IP sur la route publique (configuration `rate_limiter.yaml` existante). |

## 4. Publicité

| Règle | Détail |
|---|---|
| P1 | **Seules les campagnes autorisées pour une diffusion publique** apparaissent sur `/codes-inami`. Une campagne « réservée aux professionnels » (`professionals_only`) n'y est **jamais** diffusée : la page est publique et la déclaration de la fenêtre n'est pas une authentification (A4). |
| P2 | Ce filtre est appliqué **côté serveur** : une campagne réservée n'est jamais envoyée à cette page. Dans l'administration, cocher « Recherche INAMI » sur une campagne réservée est refusé avec un message explicite, comme aujourd'hui pour « Connexion ». |
| P3 | Toute évolution permettant d'y diffuser de la publicité pour des médicaments, ou de la publicité réservée aux professionnels, exige une **vérification réglementaire préalable** (notamment l'AR du 7 avril 1995 relatif à l'information et à la publicité concernant les médicaments à usage humain) et un mécanisme d'accès adapté. Elle n'est pas prévue. |
| P4 | Emplacement dédié **« Recherche INAMI »** (placement technique proposé : `inami_search`) : bannière en haut de page, 1200 × 300 px sur ordinateur et 640 × 200 px sur smartphone, affichée entière sans recadrage, place réservée dès le chargement. |
| P5 | Mention « Publicité » toujours visible. Lien vers l'annonceur dans un nouvel onglet (`rel="sponsored noopener noreferrer"`). |
| P6 | Une publicité par affichage. Si plusieurs sont en ligne, elles alternent à chaque visite. Pas de carrousel animé. Pas de plein écran, pas d'insertion entre les résultats. |
| P7 | Sans campagne : encart MedClick discret (ou rien). Une erreur du service publicitaire n'empêche jamais la recherche. |
| P8 | Impressions et clics mesurés comme pour les autres emplacements. |
| P9 | Gestion indépendante : onglet « Recherche INAMI » dans Administration › Publicités, avec ses propres images, dates, statut, priorité et statistiques. Les emplacements « Accueil » et « Connexion » ne sont ni modifiés ni perturbés. |
| P10 | Une publicité de cet emplacement ne partage pas d'autre emplacement : ses visuels (4:1, 16:5) ne conviennent pas aux formats 16:9 / 2:1. |

## 5. Cookies et données personnelles

| Cookie | Contenu | Durée |
|---|---|---|
| `mc_inami_specialite` | identifiant de spécialité | 12 mois |
| `mc_inami_pro` | `1` | session du navigateur |

- Ni donnée personnelle, ni traceur, ni tiers.
- Information au moment du choix de la spécialité, avec un lien vers la politique cookies.
- **Avant la mise en ligne** : référencer ces deux cookies dans les conditions d'utilisation (paragraphe « Cookies », à rendre accessible par une ancre `#cookies`). Texte proposé dans `README.md`. Faire confirmer l'exemption de consentement (cookies fonctionnels choisis par l'utilisateur).

## 6. Dépendance : refonte publicitaire

La branche backend **`feature/refonte-publicites-partenaires`** (non fusionnée au 04/11/2026)
restructure les emplacements. On y trouve `src/Advertising/Emplacements.php`, qui regroupe les
« emplacements » de l'administration et les associe aux placements techniques de `Marketing`. Le
frontend a une branche du même nom (`medclick-pwa`).

Conséquences pour « Recherche INAMI » :

1. **Ordre recommandé** : fusionner d'abord la refonte, puis déclarer « Recherche INAMI » comme un **nouvel emplacement** de ce système. Il aurait un écran « Pages publiques », le type publicités, les supports ordinateur / mobile et une capacité de 3 publicités en ligne au plus. Ainsi, la recherche ne sera pas développée deux fois.
2. Si la recherche INAMI devait passer avant la refonte : ajouter le seul placement `inami_search` à `Marketing::PLACEMENTS`, sans toucher aux placements existants. Prévenir l'équipe de la refonte pour qu'elle l'intègre à `Emplacements.php` au moment de la fusion.
3. Dans les deux cas :
   - **aucun emplacement existant n'est supprimé, renommé ni modifié** (`login`, `dashboard_desktop`, `dashboard_mobile`, `login_interstitial`, emplacements partenaires) ;
   - **aucune migration** n'est nécessaire pour le placement (`Marketing.placements` est une colonne JSON), ni pour les visuels (`bannerDesktop` / `bannerMobile` de la publicité de cet emplacement) ;
   - les règles P1, P2 et P10 sont ajoutées à la validation existante (`MarketingController` côté backend, `sponsorFields.js` côté frontend) ;
   - les tests existants des emplacements doivent rester verts, et un test doit vérifier qu'une campagne `professionals_only` n'est jamais servie à `inami_search`.

## 7. Hors périmètre

Lien de recherche partageable (`?q=`), date d'actualisation, export, comptes professionnels vérifiés.
