// Point unique de réglage du motion design (§37). Ne pas coder de durées en dur ailleurs.

export const DURATION = {
  /** Niveau 1 — micro-feedback : stepper, favoris, pression */
  micro: 160,
  press: 120,
  favorite: 260,
  /** Apparition d'un élément */
  enter: 260,
  /** Compteurs numériques (§37.1 : 500–900 ms) */
  counter: 800,
  /** Anneaux de progression (§37.4 : 700–1000 ms) */
  ring: 900,
  /** Barres d'histogramme (§37.14 : 500–800 ms) */
  bar: 600,
  pulse: 320,
  /** Navigation (§37.16 : 180–280 ms) */
  page: 240,
  /** Suppression neutre */
  collapse: 220,
} as const;

export const DELAY = {
  /** Écart entre deux éléments d'une liste échelonnée */
  stagger: 60,
  /** Le +12 % arrive après le chiffre principal (§37.15) */
  deltaAfterValue: 200,
  /** Pause avant la révélation d'un milestone (§37.5) */
  beforeMilestone: 300,
} as const;

/** Décélération naturelle en fin de course. */
export const easeOutCubic = (t: number) => 1 - Math.pow(1 - t, 3);
export const EASE_CSS = 'cubic-bezier(0.22, 1, 0.36, 1)';

export const PARTICLE_COUNT = 10; // §37.5 : 6–12 maximum
