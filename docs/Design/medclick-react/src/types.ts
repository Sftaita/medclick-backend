// Modèle de données côté front. Le backend reste la source de vérité :
// ces types décrivent le contrat attendu (voir docs/API.md).

export type Role = 'FIRST_HAND' | 'ASSISTANT' | 'OBSERVER';

export const ROLE_LABEL: Record<Role, string> = {
  FIRST_HAND: '1re main',
  ASSISTANT: 'Assistance',
  OBSERVER: 'Observation',
};
export const ROLE_SHORT: Record<Role, string> = {
  FIRST_HAND: '1re main',
  ASSISTANT: 'Assist.',
  OBSERVER: 'Observ.',
};
export const ROLES: Role[] = ['FIRST_HAND', 'ASSISTANT', 'OBSERVER'];

export type Region = 'genou' | 'hanche' | 'epaule' | 'autre';

export interface Acte {
  id: string;
  label: string;
  fullName: string;
  region: Region;
  icon: 'joint' | 'hip' | 'scope' | 'meniscus';
  nomenclature?: string;
}

export interface Surgery {
  id: string;
  acteId: string;
  /** Date ISO (AAAA-MM-JJ) */
  date: string;
  role: Role;
  surgeon: string;
  trainingYear: number;
  nomenclature?: string;
  notes?: string;
  createdAt: string;
}

/** Informations communes d'un encodage (une intervention, un lot, une journée). */
export interface CommonFields {
  date: string;
  surgeon: string;
  trainingYear: number;
  nomenclature?: string;
}

/** Une ligne d'encodage en lot : N interventions du même acte. */
export interface BatchLine {
  acteId: string;
  quantity: number;
  /** Un rôle par intervention (longueur = quantity). Si tous identiques → mode rapide. */
  roles: Role[];
}

export interface BatchRequest {
  common: CommonFields;
  lines: BatchLine[];
}

export interface YearStats {
  interventions: number;
  firstHand: number;
  consultations: number;
  guards: number;
  /** Pourcentage de complétude du carnet (0-100) */
  completion: number;
  completionTarget: number;
  /** Évolutions en %, null si non calculable proprement (aucune comparaison inventée) */
  deltas: {
    interventions: number | null;
    firstHand: number | null;
    consultations: number | null;
    completionThisMonth: number | null;
  };
}

export interface Milestone {
  id: string;
  title: string;
  subtitle: string;
  icon: 'trophy' | 'joint' | 'hip' | 'stetho' | 'moon' | 'hand';
  metric: 'interventions' | 'firstHand' | 'consultations' | 'guards' | 'completion' | 'custom';
  threshold: number;
  progress: number;
  /** Date d'obtention (ISO) ou null si non atteint */
  achievedAt: string | null;
  /** Date à laquelle la célébration a été montrée. null + achievedAt → à célébrer une seule fois. */
  celebratedAt: string | null;
}

export interface Streak {
  weeks: number;
  /** Valeur déjà vue par l'utilisateur. Si < weeks → animer l'augmentation une seule fois. */
  acknowledgedWeeks: number;
}

export interface BatchResponse {
  created: Surgery[];
  before: YearStats;
  after: YearStats;
  /** Milestones franchis PAR CET ENREGISTREMENT (backend : seuil franchi + celebratedAt null) */
  newlyAchieved: Milestone[];
}

export type DayStatusKind = 'complete' | 'incomplete' | 'empty' | 'future';

export interface DayStatus {
  date: string;
  label: string;
  status: DayStatusKind;
  summary: string;
  /** Ex. "Après-midi : non complété" */
  missing?: string;
}

export interface WeekSummary {
  start: string;
  end: string;
  label: string;
  interventions: number;
  firstHand: number;
  consultations: number;
  guards: number;
  completion: number;
  days: DayStatus[];
  incompleteDays: number;
  streak: Streak;
}

export interface DayDetail {
  date: string;
  label: string;
  morning: DayEntry[];
  afternoon: DayEntry[];
  guard: DayEntry | null;
  morningComplete: boolean;
  afternoonComplete: boolean;
}

export interface DayEntry {
  id: string;
  title: string;
  role: Role;
  time?: string;
  kind: 'surgery' | 'consultation' | 'guard';
}

export interface Partner {
  name: string;
  tagline: string;
  about: string;
  url: string;
  points: { icon: 'bulb' | 'book' | 'users' | 'target'; label: string }[];
}

export interface UserProfile {
  firstName: string;
  lastName: string;
  email: string;
  trainingYear: number;
  hospital: string;
}

export interface Dashboard {
  user: UserProfile;
  year: YearStats;
  week: WeekSummary;
  streak: Streak;
  partner: Partner;
  /** Milestones atteints mais jamais célébrés (depuis la dernière visite). */
  pendingCelebrations: Milestone[];
}

export interface MonthlyStat {
  month: string;
  count: number;
}

export interface Statistics {
  periodLabel: string;
  year: YearStats;
  guardsDelta: number | null;
  monthly: MonthlyStat[];
  byRegion: { region: string; percent: number }[];
}
