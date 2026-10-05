import type { Acte, ConsultationSpeciality, OrthoType, Region, Speciality } from '@/types';

/** Libellés des spécialités de la nomenclature (mêmes valeurs que le backend). */
export const SPECIALITY_LABEL: Record<Speciality, string> = {
  ortho: 'Chirurgie orthopédique', dig: 'Chirurgie digestive', general: 'Chirurgie générale',
  uro: 'Chirurgie urologique', vasc: 'Chirurgie vasculaire', thor: 'Chirurgie thoracique',
  plastic: 'Chirurgie plastique', neuro: 'Neurochirurgie', transp: 'Transplantation',
};
export const SPECIALITY_SHORT: Record<Speciality, string> = {
  ortho: 'Ortho', dig: 'Digestif', general: 'Générale', uro: 'Uro', vasc: 'Vasculaire',
  thor: 'Thoracique', plastic: 'Plastique', neuro: 'Neuro', transp: 'Transplantation',
};
export const SPECIALITIES = Object.keys(SPECIALITY_SHORT) as Speciality[];

/** Spécialités proposées à l'inscription (liste du front actuel). */
export const REGISTER_SPECIALITIES: Speciality[] = ['dig', 'general', 'ortho', 'plastic', 'thor', 'uro', 'vasc'];

export const CONSULTATION_SPECIALITY_SHORT: Record<ConsultationSpeciality, string> = {
  ortho: 'Ortho', traumato: 'Traumato', dig: 'Digestif', general: 'Générale',
  uro: 'Uro', vasc: 'Vasculaire', thor: 'Thoracique', plastic: 'Plastique',
};
export const CONSULTATION_SPECIALITIES = Object.keys(CONSULTATION_SPECIALITY_SHORT) as ConsultationSpeciality[];

export const ORTHO_TYPE_LABEL: Record<OrthoType, string> = { elective: 'Électif', trauma: 'Traumatologie' };

/** Régions anatomiques (`Nomenclature.subType`), dans l'ordre du carnet Excel. */
export const REGION_LABEL: Record<Region, string> = {
  shoulder: 'Épaule', humerus: 'Humérus', elbow: 'Coude', forearm: 'Avant-bras', wristhand: 'Poignet-main',
  back: 'Rachis', pelvic: 'Bassin', hip: 'Hanche', proximalFemur: 'Fémur proximal', midFemur: 'Diaphyse fémorale',
  distalFemur: 'Fémur distal', knee: 'Genou', limb: 'Jambe', ankle: 'Cheville', foot: 'Pied',
};
export const REGIONS = Object.keys(REGION_LABEL) as Region[];

export const YEAR_LABEL = (y: number) => (y === 1 ? '1re année' : `${y}e année`);
export const YEAR_LONG = ['', 'Première', 'Deuxième', 'Troisième', 'Quatrième', 'Cinquième', 'Sixième', 'Septième', 'Huitième'];

/**
 * Extrait de démonstration de la nomenclature. En production : `GET /api/nomenclature/{speciality}`.
 * Les codes INAMI ne sont pas renseignés ici (affichés « [code INAMI] »).
 */
export const DEMO_NOMENCLATURE: Acte[] = [
  { id: 'n-lca', name: 'Plastie du ligament croisé antérieur', speciality: 'ortho', orthoType: 'elective', region: 'knee' },
  { id: 'n-ptg', name: 'Prothèse totale du genou', speciality: 'ortho', orthoType: 'elective', region: 'knee' },
  { id: 'n-menisc', name: 'Méniscectomie sous arthroscopie', speciality: 'ortho', orthoType: 'elective', region: 'knee' },
  { id: 'n-osteot', name: 'Ostéotomie tibiale de valgisation', speciality: 'ortho', orthoType: 'elective', region: 'knee' },
  { id: 'n-pth', name: 'Prothèse totale de hanche', speciality: 'ortho', orthoType: 'elective', region: 'hip' },
  { id: 'n-arthro-ep', name: "Arthroscopie de l'épaule", speciality: 'ortho', orthoType: 'elective', region: 'shoulder' },
  { id: 'n-arthrodese', name: 'Arthrodèse lombaire', speciality: 'ortho', orthoType: 'elective', region: 'back' },
  { id: 'n-hallux', name: 'Hallux valgus', speciality: 'ortho', orthoType: 'elective', region: 'foot' },
  { id: 'n-radius', name: 'Ostéosynthèse du radius distal', speciality: 'ortho', orthoType: 'trauma', region: 'wristhand' },
  { id: 'n-clou', name: 'Enclouage fémoral', speciality: 'ortho', orthoType: 'trauma', region: 'midFemur' },
  { id: 'n-cheville', name: 'Ostéosynthèse de la cheville', speciality: 'ortho', orthoType: 'trauma', region: 'ankle' },
  { id: 'n-chole', name: 'Cholécystectomie par cœlioscopie', speciality: 'dig' },
  { id: 'n-appendic', name: 'Appendicectomie', speciality: 'dig' },
  { id: 'n-hernie', name: 'Cure de hernie inguinale', speciality: 'general' },
];

export const nomenclatureById: Record<string, Acte> = Object.fromEntries(DEMO_NOMENCLATURE.map((a) => [a.id, a]));
