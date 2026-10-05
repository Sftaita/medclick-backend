# Motion design (§37)

Principe : les animations accompagnent l'action, elles n'en contrôlent jamais le rythme. La donnée
backend reste la source de vérité ; une animation ne fait que la mettre en scène.

## Réglages centraux — `src/motion/tokens.ts`

| Jeton | Valeur | Usage |
|---|---|---|
| `micro` | 160 ms | stepper, petits retours |
| `press` | 120 ms | `scale(0.98)` à l'appui (`.pressable`) |
| `favorite` | 260 ms | étoile 0,8 → 1,1 → 1 |
| `enter` | 260 ms | apparition (translation 8 px + fondu) |
| `counter` | 800 ms | compteurs |
| `ring` | 900 ms | anneaux de progression |
| `bar` | 600 ms | barres / histogramme |
| `pulse` | 320 ms | impulsion d'un bon résultat |
| `page` | 240 ms | transition d'écran |
| `stagger` | 60 ms | décalage entre éléments |
| `deltaAfterValue` | 200 ms | l'évolution arrive après le chiffre |
| `beforeMilestone` | 300 ms | pause avant la révélation d'un milestone |
| Courbe | `cubic-bezier(.22,1,.36,1)` | décélération naturelle |

## Primitives — `src/motion/index.tsx`

| Primitive | Ce qu'elle fait |
|---|---|
| `useTween(to, opts)` | interpolation rAF, valeur finale exacte, repart de la valeur affichée si `to` change |
| `AnimatedNumber` | compteur 0 → valeur (ou `from` → valeur, ex. 121 → 124) |
| `AnimatedProgressRing` | anneau dessiné + `pulseOnDone` ; `children(v)` pour le libellé |
| `AnimatedProgressBar` | barre en `scaleX` (pas de recalcul de layout) |
| `Reveal` / `StaggeredList` | apparition simple ou échelonnée (`fadeOnly` pour un simple fondu) |
| `SuccessFeedback` | ✓ qui se dessine + titre |
| `AchievementReveal` | carte milestone, trophée qui bouge brièvement, 10 particules |
| `CountStepper` | − N + avec petit mouvement du chiffre (haut / bas) |
| `FavoriteStar` | étoile avec rotation/échelle à l'activation |
| `Collapsible` | suppression neutre : la ligne se contracte puis disparaît |
| `useReducedMotion` | respect de `prefers-reduced-motion` |

## Trois niveaux de récompense

1. **Micro-feedback** (fréquent, presque imperceptible) : appui, favori, quantité, ✓ enregistré.
2. **Progression** (visible) : semaine complétée, série +1, palier de complétude.
3. **Milestone** (rare, marquant mais professionnel) : carte trophée + particules, 1 à 1,5 s, une seule fois.

## Règles

- Skeleton tant que la donnée n'est pas chargée : jamais de « 0 » qui monte vers 124.
- À l'ouverture d'un écran, une ou deux animations principales maximum ; le reste en simple fondu.
- Aucune animation permanente ni répétée (feu, sponsor, graphiques).
- Suppression : animation neutre, confirmation d'abord.
- Les boutons d'action restent utilisables pendant les animations de confirmation.
- `prefers-reduced-motion` : valeurs finales immédiates, sans translation ni confettis (`motion.css` + `useTween`).
- Seules `opacity`, `transform` et `stroke` sont animées (exception : repli de suppression via `grid-template-rows`).
