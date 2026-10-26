# MedClick — page d'accueil publique (React)

La page d'accueil vue avant connexion (medclick.be) : hero avec deux téléphones, types d'activités, fonctionnalités, « Comment ça marche », bloc « créée par des assistants » + partenaire, FAQ, appel final et pied de page.

Aucune dépendance en plus de React 18 : pas de librairie d'icônes, pas de CSS-in-JS. Testé avec Vite 5 + React 18.3, de 390 px à 1440 px de large.

## Contenu du dossier

```
src/pages/Landing/
├── LandingPage.jsx        ← la page (export par défaut)
├── LandingPage.css        ← tous les styles, préfixés .lp- / .ps-
├── landingContent.js      ← TOUS les textes + routes + e-mail + version
└── components/
    ├── Logo.jsx           ← croix + mot-symbole MedClick
    ├── Icons.jsx          ← icônes au trait + croix décorative
    └── PhoneMockup.jsx    ← cadre de téléphone (capture ou écran d'exemple)
docs/
├── apercu-ordinateur.png
└── apercu-mobile.png
example/
└── App.example.jsx        ← exemple d'intégration avec react-router
```

## Installation

1. Copiez `src/pages/Landing/` dans votre projet (par ex. `src/pages/Landing/`).
2. Vérifiez que la police **Plus Jakarta Sans** est chargée (elle l'est déjà dans MedClick). Sinon, dans `index.html` :
   ```html
   <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
   ```
3. Affichez la page sur la route publique `/` :

```jsx
import { Link } from 'react-router-dom';
import LandingPage from './pages/Landing/LandingPage';

<Route path="/" element={<LandingPage LinkComponent={Link} />} />
```

Sans `LinkComponent`, la page utilise de simples balises `<a href>`.

## Props

| Prop | Type | Défaut | Rôle |
|---|---|---|---|
| `LinkComponent` | composant | `null` (→ `<a>`) | Composant de lien du routeur. Reçoit `to`, `className`, `children`. |
| `config` | objet | `landingConfig` | Surcharge partielle : `loginHref`, `registerHref`, `termsHref`, `privacyHref`, `partnersHref`, `contactEmail`, `version`. |
| `partner` | `{ name, logoSrc?, href?, tagline? }` | — | Bloc « Application sponsorisée par ». **Omis → le bloc est masqué** et le texte « Qu'est-ce que MedClick ? » prend toute la largeur (période sans partenaire). |
| `screenshots` | `{ front?, back? }` | — | Captures pour les deux téléphones du hero (390 × 844) : `front` = Accueil, `back` = Mes activités. Omis → écrans d'exemple dessinés en JSX. |

### Exemple complet

```jsx
<LandingPage
  LinkComponent={Link}
  config={{
    loginHref: '/connexion',
    registerHref: '/inscription',
    contactEmail: 'contact@medclick.be',
    version: import.meta.env.VITE_APP_VERSION ?? '3.1',
  }}
  partner={{
    name: 'Nom du partenaire',
    logoSrc: '/partners/logo.svg',
    href: '/partenaires',
    tagline: 'Ensemble pour former les chirurgiens de demain',
  }}
  screenshots={{ front: '/landing/accueil.png', back: '/landing/interventions.png' }}
/>
```

## Personnaliser

### Les textes
Tout est dans **`landingContent.js`** : hero, types d'activités, étapes, bloc « origine », FAQ, appel final. Le composant n'a pas à être modifié pour changer un texte.

La FAQ est un tableau ; chaque entrée a :
- `question` : le titre ;
- `answer` : un tableau de paragraphes ;
- `steps` (optionnel) : liste numérotée `[['Libellé', 'texte'], …]`.

La première question est ouverte par défaut. L'accordéon utilise `<details>` / `<summary>` natifs : clavier et lecteurs d'écran sont gérés sans JavaScript.

### L'e-mail de contact
`contactEmail` est vide par défaut : les liens « Nous contacter » et « Écrivez-nous » sont alors **masqués**. Renseignez-le dans `landingContent.js` ou via `config`.

### Les couleurs
`LandingPage.css` lit les tokens de MedClick (`--mc-primary`, `--mc-ink`, `--mc-gradient`, …). Si `tokens.css` est chargé, la page suit automatiquement le thème ; sinon, les valeurs de repli intégrées (identiques) s'appliquent. Les rôles reprennent `--mc-role-1` (1re main), `--mc-role-3` (1re main aidée) et `--mc-role-2` (2e main).

### Les captures des téléphones
Pour remplacer les écrans d'exemple par de vraies captures :
1. Capturez l'écran Accueil et « Mes activités » en **390 × 844 px** (DevTools → mode appareil).
2. Placez-les dans `public/landing/`.
3. Passez-les via `screenshots={{ front, back }}`. Les images sont cadrées en haut (`object-position: top`).

Les écrans d'exemple reprennent l'Accueil et « Mes activités » de la nouvelle application (`docs/Design/medclick-react`), avec des données fictives d'illustration (124 interventions, 72 %, etc.).

## Responsive

| Largeur | Comportement |
|---|---|
| > 1020 px | Hero sur deux colonnes, cartes de fonctionnalités en grille 4 + 2 / 3 + 3. |
| 641–1020 px | Tout passe sur une colonne, les liens de navigation disparaissent. |
| ≤ 640 px | Un seul téléphone, boutons pleine largeur, « Se connecter » retiré du bandeau (il reste dans le hero et en bas de page). |

## Accessibilité

- Vrais `<a>`, `<button>`, `<details>` : tout est atteignable au clavier, avec un contour de focus visible.
- Les maquettes décoratives (téléphones, démos des cartes) sont en `aria-hidden`.
- Les contrastes de texte respectent 4,5:1.
- Les animations sont désactivées si l'utilisateur a choisi « réduire les animations ».

## Isolation CSS

Toutes les classes commencent par `.lp-` (ou `.ps-` pour les écrans d'exemple), et les resets (`margin: 0` sur les titres, etc.) ne s'appliquent qu'à l'intérieur de `.lp`. La page n'affecte donc pas le reste de l'application. Le `nav` est `position: sticky` : si votre layout ajoute déjà une barre en haut, affichez la page **sans** ce layout.

## Points à valider avant mise en ligne

- **Adresse de contact** : à renseigner (`contactEmail`).
- **Réponse « données »** : la mention « à des fins marketing » mérite d'être précisée (destinataires, finalité) pour être conforme au RGPD et rassurer les utilisateurs.
- **Routes** : vérifiez `loginHref`, `registerHref`, `termsHref`, `privacyHref`, `partnersHref` par rapport à votre routeur.
