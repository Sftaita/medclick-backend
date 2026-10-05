# MedClick — front React (v2, relooking)

Carnet de stage numérique pour les internes en orthopédie : encodage rapide des interventions,
progression, milestones et statistiques. PWA responsive : mobile, tablette (rail de navigation) et ordinateur (barre latérale). Voir `docs/PLAN.md` › Responsive.

## Démarrer

```bash
npm install
cp .env.example .env.local   # VITE_USE_MOCK=true → fonctionne sans backend
npm run dev                  # http://localhost:5173
npm run build                # vérification TypeScript + build de production
```

Avec `VITE_USE_MOCK=true`, une API simulée en mémoire (`src/api/mock.ts`) reproduit le
comportement attendu du serveur, y compris l'encodage en lot atomique et la règle
« un milestone n'est célébré qu'une seule fois ». Pour brancher le vrai backend :
`VITE_USE_MOCK=false`, `VITE_API_URL=/api` (et le proxy dans `vite.config.ts` en dev).

## Documentation

| Fichier | Contenu |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | Plan complet : routes, écrans, composants, données, animations par page |
| [`docs/API.md`](docs/API.md) | Contrat backend : endpoints, payloads, transaction du lot, milestones |
| [`docs/MOTION.md`](docs/MOTION.md) | Système d'animation (§37) : tokens, primitives, niveaux de récompense |

## Structure

```
src/
  api/          contrat (types.ts), client HTTP (http.ts), API simulée (mock.ts), sélection (index.ts)
  components/   Icon, ui (Logo, TopBar, TabBar, Skeleton, DeltaBadge…), CommonFieldsForm, SaveResult
  data/         catalogue d'actes de démarrage
  hooks/        useAsync (chargement → skeleton, jamais de faux 0)
  layouts/      AppLayout (barre d'onglets + transition de page)
  lib/          formatage dates / pourcentages
  motion/       tokens, useTween, useReducedMotion, AnimatedNumber, AnimatedProgressRing,
                AnimatedProgressBar, Reveal, StaggeredList, SuccessFeedback, AchievementReveal,
                CountStepper, FavoriteStar, Collapsible
  pages/        un fichier par écran (voir docs/PLAN.md)
  styles/       tokens.css (identité visuelle), global.css, motion.css, responsive.css
  types.ts      modèle de données partagé
public/
  images/       logo.svg, login-photo.jpg (à remplacer par la photo HD)
  manifest.webmanifest
```

## À compléter avant production

- Remplacer `public/images/login-photo.jpg` (extrait basse définition de la maquette) par la photo HD.
- Remplacer le logo OrthoNova dessiné (`PartnerMark`) par le fichier officiel du partenaire.
- Charger catalogue d'actes, chirurgiens et objectifs du carnet depuis le backend.
- Ajouter un service worker (ex. `vite-plugin-pwa`) pour le mode hors-ligne.
- Brancher l'authentification réelle (`api.login`) et la garde de routes.
