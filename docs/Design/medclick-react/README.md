# MedClick — front React (v2, relooking)

Carnet de stage numérique des médecins assistants en chirurgie : encodage rapide des interventions,
consultations, gardes et formations, progression, milestones, statistiques et export du carnet officiel.
PWA responsive : mobile, tablette (rail de navigation) et ordinateur (barre latérale). Voir `docs/PLAN.md`.

Le modèle de données suit le backend de ce dépôt (rôles `position` 1/3/2, nomenclature INAMI par spécialité,
type et région, années, chirurgiens, favoris…). Ce qui a été retiré ou reste à alléger : `../A-ALLEGER.md`.

## Démarrer

```bash
npm install
cp .env.example .env.local   # VITE_USE_MOCK=true → fonctionne sans backend
npm run dev                  # http://localhost:5173
npm run build                # vérification TypeScript + build de production
```

Avec `VITE_USE_MOCK=true`, une API simulée en mémoire (`src/api/mock.ts`) applique les règles du serveur
(superviseur obligatoire selon le rôle, chirurgien supprimé avec ses interventions, lot atomique, milestone
célébré une seule fois…). Démo : un e-mail contenant « inactif » simule un compte non activé ;
`/reinitialiser/expire` simule un lien expiré ; `/admin/partenaire` permet de retirer le partenaire.

Pour brancher le vrai backend : `VITE_USE_MOCK=false`, `VITE_API_URL=/api` (et le proxy dans `vite.config.ts`
en dev). Les écrans qui dépendent de routes encore absentes affichent une erreur « Route à créer ».

## Documentation

| Fichier | Contenu |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | Règles métier, routes, écrans, emplacements du partenaire, composants |
| [`docs/API.md`](docs/API.md) | Correspondance avec le backend, routes existantes utilisées, routes à créer |
| [`docs/MOTION.md`](docs/MOTION.md) | Système d'animation (§37) : tokens, primitives, niveaux de récompense |
| [`../A-ALLEGER.md`](../A-ALLEGER.md) | Ce qui n'a plus lieu d'être (maquette, front actuel, backend) |

## Structure

```
src/
  api/          contrat (types.ts), backend réel (http.ts), API simulée (mock.ts), sélection (index.ts)
  components/   Icon, ui (Logo, SponsorMark, TopBar, TabBar, ActivityTabs, ActeIcon…), CommonFieldsForm,
                YearSelect, SaveResult, StatusHero, TermsGate, CampaignInterstitial
  data/         referentiel.ts : spécialités, régions, années, extrait de nomenclature pour la démo
  hooks/        useAsync (chargement → skeleton, jamais de faux 0), usePartner (source unique du sponsor)
  layouts/      AppLayout (navigation, publicité après connexion, CGU)
  lib/          formatage dates / heures / pourcentages
  motion/       primitives d'animation (voir docs/MOTION.md)
  pages/        un fichier par écran (voir docs/PLAN.md)
  styles/       tokens.css (identité visuelle), global.css, motion.css, responsive.css
  types.ts      modèle de données partagé, aligné sur le backend
public/
  images/       logo.svg, login-photo.jpg (à remplacer par la photo HD)
  manifest.webmanifest
```

## À compléter avant production

- Créer côté backend les routes listées dans `docs/API.md` › « Routes à créer » (lot transactionnel,
  partenaire, tableau de bord, semaine, jour, statistiques, milestones).
- Fournir le référentiel des objectifs du carnet (aujourd'hui fixés en dur dans `ProgressionPage`).
- Remplacer `public/images/login-photo.jpg` (extrait basse définition) par la photo HD.
- Rédiger les pages d'information (aide, à propos, confidentialité) et l'écran « Mes informations ».
- Ajouter un service worker (ex. `vite-plugin-pwa`) pour le mode hors-ligne, et la garde de routes (JWT).
- Reprendre la page `/admin/partenaire` dans le back-office.
