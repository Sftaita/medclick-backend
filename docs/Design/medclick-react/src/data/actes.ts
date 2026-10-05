import type { Acte } from '@/types';

// Catalogue local de démarrage. En production, charger /api/actes (et la nomenclature officielle).
export const ACTES: Acte[] = [
  { id: 'lca', label: 'LCA', fullName: 'Reconstruction du ligament croisé antérieur', region: 'genou', icon: 'joint', nomenclature: 'K50 - Plastie LCA' },
  { id: 'ptg', label: 'PTG', fullName: 'Prothèse totale de genou', region: 'genou', icon: 'joint' },
  { id: 'pth', label: 'PTH', fullName: 'Prothèse totale de hanche', region: 'hanche', icon: 'hip' },
  { id: 'arthro-genou', label: 'Arthroscopie genou', fullName: 'Arthroscopie du genou', region: 'genou', icon: 'scope' },
  { id: 'menisc', label: 'Méniscectomie', fullName: 'Méniscectomie arthroscopique', region: 'genou', icon: 'meniscus' },
  { id: 'arthro-epaule', label: 'Arthroscopie épaule', fullName: "Arthroscopie de l'épaule", region: 'epaule', icon: 'scope' },
  { id: 'osteo-femur', label: 'Ostéosynthèse fémur', fullName: 'Ostéosynthèse du fémur', region: 'autre', icon: 'scope' },
];

export const FAVORITE_ACTE_IDS = ['lca', 'ptg', 'pth', 'arthro-genou', 'menisc'];

export const actesById: Record<string, Acte> = Object.fromEntries(ACTES.map((a) => [a.id, a]));

export const SURGEONS = ['Dr De Muylder'];
export const TRAINING_YEARS = [1, 2, 3, 4, 5, 6];
