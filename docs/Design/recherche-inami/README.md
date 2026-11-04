# Recherche publique des codes INAMI — design

État : **design validé, en attente de validation visuelle finale ; aucune implémentation**. Ce
dossier conserve les fichiers de conception et une prévisualisation locale navigable (ordinateur,
smartphone, administration). Règles fonctionnelles : **`SPECIFICATIONS.md`**.

Point de départ : la proposition React fournie dans
`SurgicalHub/docs/design/Instruction design/medclick-inami` (captures d'origine dans
`captures-origine/`), reprise et ajustée sur le frontend réel (`medclick-pwa`, dossier
`frontend/`) et sur la nomenclature réelle.

## Lancer la prévisualisation

Prérequis : PHP 8.4 avec `pdo_mysql`, WAMP démarré (MySQL) et un `DATABASE_URL` local dans le
`.env` / `.env.local` du backend.

```bash
php -S 127.0.0.1:8765 -t docs/Design/recherche-inami/public
# puis ouvrir http://127.0.0.1:8765/index.html
```

L'aperçu construit (`public/app.js`, `public/app.css`) est versionné : il s'ouvre sans Node.
**Après une modification** des sources (`src/`, `apercu/`), reconstruisez-le :

```bash
cd docs/Design/recherche-inami
npm install          # une fois (esbuild + React 18, dossier node_modules ignoré par Git)
npm run build        # ou : node build.mjs
npm test             # 7 tests de la logique de recherche
```

**Lancez toujours le serveur sur `127.0.0.1`** (jamais `0.0.0.0`). Le script
`public/nomenclature.php` refuse de toute façon :
- toute requête qui ne vient pas de la machine elle-même ;
- toute méthode autre que GET ;
- toute exécution hors de `php -S` (Apache, LiteSpeed) ;
- toute base qui n'est pas locale.

Il ne contient aucun identifiant et ne renvoie aucun détail d'erreur.

La barre du haut permet de choisir l'écran (Connexion, Recherche, Administration), l'appareil,
la bannière (Campagne, Encart MedClick, Aucune) et l'état des données (Normal, Lent, Erreur).
« Réinitialiser » efface les deux cookies (première visite).

Les données sont lues **en direct** dans la base MySQL locale à chaque chargement
(`public/nomenclature.php`, lecture seule, uniquement sous `php -S`). Il n'y a aucune copie
statique. Si MySQL est arrêté, la page affiche son état d'erreur. Le visuel publicitaire est fictif.

## Décisions validées (04/11/2026)

| Sujet | Décision |
|---|---|
| Parcours | Connexion → « Trouver un code INAMI » → « Êtes-vous professionnel de la santé ? » → recherche. |
| Bouton | Dans la carte de connexion, sous « Se connecter », après un séparateur « ou ». Style secondaire. |
| URL permanente | `/codes-inami`, publique, accessible directement (la fenêtre s'affiche si besoin). |
| Accès | Gratuit, sans compte. |
| Colonnes | Nom · Code ambulatoire · Code hospitalisation · N ou K. |
| Bannière | Nouvel emplacement **« Recherche INAMI »** : 1200 × 150 px (ordinateur), 640 × 200 px (smartphone), affichée en entier, sans recadrage. Mention **« Publicité »**. |
| Gestion | Onglet « Recherche INAMI » dans Administration › Publicités, indépendant de « Accueil » et « Connexion ». Ses images, dates, statut et statistiques lui sont propres. |
| Campagnes réservées aux professionnels | **Jamais diffusées** sur `/codes-inami` : la page est publique et la déclaration n'est pas une authentification. Seules les campagnes à diffusion publique. Toute évolution (médicaments) exige une vérification réglementaire préalable. |
| Spécialité | Cookie `mc_inami_specialite`, 12 mois, identifiant de spécialité seulement. |
| Données | Toujours la nomenclature actuelle de MedClick (table `nomenclature`), jamais une copie. |
| Date d'actualisation | **Non affichée** : la table `nomenclature` n'a aucune date fiable (l'administration modifie des lignes à tout moment, sans horodatage). Voir « Plus tard ». |

## Ajustements apportés à la proposition d'origine

- Libellés réels des spécialités (`frontend/src/shared/constants.js`). Seules les spécialités présentes dans la nomenclature sont proposées.
- En-tête aligné sur l'application (« Se connecter » + « S'enregistrer », visibles aussi sur smartphone).
- Titre de la fenêtre : « Êtes-vous professionnel de la santé ? ».
- **Confirmation retenue pour la session du navigateur** : cookie de session `mc_inami_pro` (au lieu du `sessionStorage`, limité à un onglet). La fenêtre ne réapparaît ni dans un nouvel onglet, ni en revenant sur la page, ni via le bouton de la connexion. Elle revient après la fermeture du navigateur.
- N/K : environ 90 % des valeurs réelles sont des « N » (format `N200`). N est en bleu (couleur principale) et K en violet.
- Noms longs (jusqu'à environ 700 caractères) : colonne Nom à largeur minimale, retour à la ligne soigné.
- Recherche par numéro : la partie trouvée du code est surlignée.
- Smartphone :
  - le champ de recherche **reste accroché en haut** pendant le défilement ;
  - après le choix d'une spécialité par raccourci, le champ prend le focus (clavier ouvert) ;
  - la touche « Rechercher » du clavier ferme le clavier pour libérer l'écran ;
  - les résultats suivants se chargent **automatiquement** en approchant du bas (le bouton reste disponible).
- Mention du cookie au moment du choix (« … 12 mois, sans aucune donnée personnelle. En savoir plus ») et lien « Cookies » en pied de page.
- Sous-titre : « Gratuit et sans compte : … ».

## Cookies

| Nom | Contenu | Durée | Rôle |
|---|---|---|---|
| `mc_inami_specialite` | identifiant de la spécialité (ex. `ortho`) | 12 mois | Retrouver la dernière spécialité choisie. |
| `mc_inami_pro` | `1` | session du navigateur | Ne pas redemander la déclaration « professionnel de la santé ». |

Les deux cookies ont les attributs `SameSite=Lax` et `Secure` en HTTPS. Aucune donnée personnelle, aucun traceur, aucun tiers.

**Référencement** : MedClick n'a pas de politique cookies dédiée. Il n'y a qu'un paragraphe
« c) Cookies et sécurité » dans `frontend/src/auth/pages/ConditionsDialog.jsx`. Il faut y lister
ces deux cookies (ou créer une page dédiée). C'est la cible du lien « Cookies / En savoir plus »
(`/conditions-utilisation#cookies`, à créer).

Proposition de texte :

> **Recherche des codes INAMI.** Deux cookies fonctionnels, sans donnée personnelle, sont déposés
> uniquement si vous utilisez cette recherche : `mc_inami_specialite` retient pendant 12 mois la
> spécialité que vous avez choisie ; `mc_inami_pro` retient, jusqu'à la fermeture du navigateur,
> que vous avez déclaré être professionnel de la santé. Vous pouvez les supprimer à tout moment
> depuis les réglages de votre navigateur.

**Obligations à faire confirmer** (je ne suis pas juriste) :
- En Belgique, l'article 10/2 de la loi du 13 juin 2005 relative aux communications électroniques (transposition de l'article 5.3 de la directive ePrivacy) impose le consentement pour tout cookie, **sauf** s'il est strictement nécessaire à un service demandé expressément par l'utilisateur.
- Les cookies de personnalisation de l'interface choisis par l'utilisateur sont généralement considérés comme exemptés : WP29, avis 04/2012 ; lignes directrices de l'Autorité de protection des données. Une information claire est attendue au moment du choix, surtout pour une durée longue (12 mois). C'est ce que prévoit le design.
- Aucun bandeau de consentement ne semble donc nécessaire pour ces deux cookies. Le texte des conditions doit être mis à jour avant la mise en ligne.

## Administration des publicités

Voir l'écran « 3. Administration » de la prévisualisation.

- Troisième onglet « Recherche INAMI » à côté de « Accueil » et « Connexion », avec ses propres images. Formats recommandés : 1200 × 150 px et 640 × 200 px. Les visuels sont affichés entiers.
- Aperçu « Recherche INAMI ordinateur / mobile ».
- Une publicité par affichage. Si plusieurs sont en ligne, elles alternent à chaque visite. Pas de carrousel animé sur une page de travail.
- Impressions et clics mesurés comme pour les autres emplacements.

Constat technique (pour plus tard) : `Marketing.placements` est une colonne JSON. Un placement
`inami_search` s'ajoute **sans migration**, et les champs `bannerDesktop` / `bannerMobile` d'une
publicité de cet onglet portent ses visuels 8:1 et 16:5. Une publicité ne doit donc pas partager
l'emplacement INAMI avec un emplacement 16:9 / 2:1 (règle de validation à prévoir).

**Dépendance** avec la branche `feature/refonte-publicites-partenaires` : voir
`SPECIFICATIONS.md`, section 6. L'emplacement s'y ajoute sans supprimer ni modifier les emplacements
existants.

## Questions encore ouvertes

1. Lien de recherche partageable (`/codes-inami?q=genou`) : non prévu, à ajouter si utile.
2. Ordre de fusion avec la refonte publicitaire (`SPECIFICATIONS.md`, section 6).

## Plus tard (implémentation, après validation)

- Backend : route publique en lecture seule (spécialités + codes), lue en direct dans `nomenclature`, avec cache court (quelques minutes) pour que les modifications de l'administration apparaissent vite. Limite de requêtes par IP (`rate_limiter.yaml`).
- Pas de migration pour la recherche ni pour l'emplacement publicitaire.
- Date d'actualisation : seulement si une date fiable est ajoutée un jour (par exemple une date de dernière modification de la nomenclature mise à jour par l'administration, ce qui demanderait une migration). Sinon, rien n'est affiché.
- Frontend : route `/codes-inami` hors des routes protégées, bouton dans `LoginPage.jsx`, onglet dans Administration › Publicités.

## Contenu

```
src/features/inami/   composants de la page (référence pour l'intégration)
apercu/               code propre à l'aperçu (répliques connexion et administration, données, routes)
public/               aperçu construit (index.html = barre de navigation, app.html = application)
public/nomenclature.php  lecture locale de la nomenclature (aperçu seulement, jamais déployé)
tests/search.test.mjs    tests de la logique de recherche (node --test, 7 tests)
SPECIFICATIONS.md     spécifications fonctionnelles (accès, recherche, publicité, cookies, dépendances)
package.json          outils de construction de l'aperçu (esbuild, React 18)
captures-origine/     captures de la proposition d'origine
build.mjs             construction de l'aperçu
```
