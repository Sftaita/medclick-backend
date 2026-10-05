// Modèle de données côté front, aligné sur le backend MedClick (entités Symfony).
// Le backend reste la source de vérité : voir docs/API.md pour la correspondance champ par champ.

/* ------------------------------------------------------------------ Rôles */
/** Rôle du résident pendant l'intervention (`Surgeries.position`). */
export type Role = 'SOLO' | 'ASSISTED' | 'SECOND';

/** Valeur stockée par le backend : 1 = 1re main solo, 3 = 1re main aidée, 2 = 2e main. */
export const ROLE_POSITION: Record<Role, 1 | 2 | 3> = { SOLO: 1, ASSISTED: 3, SECOND: 2 };
export const ROLE_LABEL: Record<Role, string> = { SOLO: '1re main', ASSISTED: '1re main aidée', SECOND: '2e main' };
export const ROLE_SHORT: Record<Role, string> = { SOLO: '1re main', ASSISTED: '1re aidée', SECOND: '2e main' };
export const ROLES: Role[] = ['SOLO', 'ASSISTED', 'SECOND'];

/**
 * Superviseur demandé selon le rôle (règle du front actuel) :
 * 1re main aidée → qui a aidé (`secondHand`) ; 2e main → qui était en 1re main (`firstHand`) ; solo → aucun.
 */
export const SUPERVISOR_LABEL: Record<Role, string | null> = { SOLO: null, ASSISTED: 'Aidé par', SECOND: '1re main' };

/* ------------------------------------------------------------- Référentiels */
/** Spécialités de la nomenclature INAMI (`Nomenclature.speciality`). */
export type Speciality = 'ortho' | 'dig' | 'general' | 'uro' | 'vasc' | 'thor' | 'plastic' | 'neuro' | 'transp';
/** Spécialités d'une consultation (`Consultations.speciality`) : la traumatologie y est séparée. */
export type ConsultationSpeciality = 'ortho' | 'traumato' | 'dig' | 'general' | 'uro' | 'vasc' | 'thor' | 'plastic';
/** Type d'acte orthopédique (`Nomenclature.type`) : 1 = électif, 2 = traumatologie. */
export type OrthoType = 'elective' | 'trauma';
/** Région anatomique (`Nomenclature.subType`, orthopédie). */
export type Region =
  | 'shoulder' | 'humerus' | 'elbow' | 'forearm' | 'wristhand' | 'back' | 'pelvic' | 'hip'
  | 'proximalFemur' | 'midFemur' | 'distalFemur' | 'knee' | 'limb' | 'ankle' | 'foot';

/** Une entrée de la nomenclature INAMI. */
export interface Acte {
  /** Id de la nomenclature (`surgeryId` côté API) */
  id: string;
  name: string;
  speciality: Speciality;
  orthoType?: OrthoType;
  region?: Region;
  /** `codeHospitalisation . n` — non renseigné dans la démo */
  code?: string;
}

/** Raccourci personnel vers la nomenclature (`Favorites`). */
export interface Favorite {
  id: string;
  shortcut: string;
  acte: Acte;
}

/* ------------------------------------------------------- Années et chirurgiens */
export interface TrainingYear {
  id: string;
  /** 1 à 8 */
  yearOfFormation: number;
  /** AAAA-MM-JJ */
  dateOfStart: string;
  hospital: string;
  /** Maître de stage (texte libre côté backend) */
  master: string;
}

export interface Surgeon {
  id: string;
  firstName: string;
  lastName: string;
  yearId: string;
  /** Maître de stage : un seul par année */
  boss: boolean;
}

/* ------------------------------------------------------------ Interventions */
export interface Surgery {
  id: string;
  /** Copie de l'entrée de nomenclature (le backend copie nom, spécialité et code dans l'intervention) */
  acte: Acte;
  /** AAAA-MM-JJ (pas d'heure ni de demi-journée côté backend) */
  date: string;
  role: Role;
  /** Superviseur (`firstHand` ou `secondHand` selon le rôle) */
  supervisorId?: string;
  yearId: string;
  createdAt: string;
}

/** Informations communes d'un encodage (une intervention, un lot, une journée). */
export interface CommonFields {
  date: string;
  yearId: string;
  /** Utilisé pour les rôles ASSISTED et SECOND */
  supervisorId?: string;
}

/** Une ligne d'encodage en lot : N interventions du même acte. */
export interface BatchLine {
  acteId: string;
  quantity: number;
  /** Un rôle par intervention (longueur = quantity). */
  roles: Role[];
}

export interface BatchRequest {
  common: CommonFields;
  lines: BatchLine[];
}

/* ---------------------------------------------- Consultations, gardes, formations */
export type DayPart = 'morning' | 'afternoon' | 'night';
export const DAY_PART_LABEL: Record<DayPart, string> = { morning: 'Matin', afternoon: 'Après-midi', night: 'Nuit' };

export interface Consultation {
  id: string;
  date: string;
  dayPart: DayPart;
  speciality: ConsultationSpeciality;
  /** Nombre de patients vus */
  number: number;
  yearId: string;
}

export interface Garde {
  id: string;
  /** AAAA-MM-JJTHH:MM */
  dateOfStart: string;
  dateOfEnd: string;
  /** Patients vus pendant la garde */
  number: number;
  yearId: string;
}

export type FormationEvent = 'staff' | 'journal' | 'lesson' | 'congres';
export type FormationRole = 'participant' | 'speaker' | 'organiser';
export const FORMATION_EVENT_LABEL: Record<FormationEvent, string> = { staff: 'Staff', journal: 'Journal', lesson: 'Cours', congres: 'Congrès' };
export const FORMATION_ROLE_LABEL: Record<FormationRole, string> = { participant: 'Participant', speaker: 'Orateur', organiser: 'Organisateur' };

export interface Formation {
  id: string;
  event: FormationEvent;
  /** Sujet */
  name: string;
  description?: string;
  dateOfStart: string;
  dateOfEnd: string;
  /** true = à l'hôpital de stage (`location = 'local'` côté backend) */
  local: boolean;
  location?: string;
  role: FormationRole;
  yearId: string;
}

/* ------------------------------------------------------------- Statistiques */
export interface YearStats {
  interventions: number;
  /** 1re main solo + 1re main aidée */
  firstHand: number;
  consultations: number;
  guards: number;
  formations: number;
  /** Pourcentage de complétude du carnet (0-100) — objectif à fournir par le backend */
  completion: number;
  completionTarget: number;
  /** Évolutions en %, null si non calculable (aucune comparaison inventée) */
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
  icon: 'trophy' | 'knee' | 'hip' | 'stetho' | 'moon' | 'hand';
  metric: 'interventions' | 'firstHand' | 'consultations' | 'guards' | 'completion' | 'custom';
  threshold: number;
  progress: number;
  achievedAt: string | null;
  /** null + achievedAt → à célébrer une seule fois */
  celebratedAt: string | null;
}

export interface Streak {
  weeks: number;
  acknowledgedWeeks: number;
}

export interface BatchResponse {
  created: Surgery[];
  before: YearStats;
  after: YearStats;
  newlyAchieved: Milestone[];
}

/** Un jour est « complété » dès qu'au moins une activité y est encodée. */
export type DayStatusKind = 'complete' | 'empty' | 'future';

export interface DayStatus {
  date: string;
  label: string;
  status: DayStatusKind;
  summary: string;
}

export interface WeekSummary {
  start: string;
  end: string;
  label: string;
  interventions: number;
  firstHand: number;
  consultations: number;
  guards: number;
  /** Jours ouvrés complétés / jours ouvrés écoulés */
  completion: number;
  days: DayStatus[];
  incompleteDays: number;
  streak: Streak;
}

export interface DayDetail {
  date: string;
  label: string;
  surgeries: Surgery[];
  consultations: Partial<Record<DayPart, Consultation>>;
  garde: Garde | null;
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
  bySpeciality: { label: string; percent: number }[];
}

/* -------------------------------------------------------- Compte et contenus */
export interface UserProfile {
  firstName: string;
  lastName: string;
  email: string;
  speciality: Speciality;
  /** Année de formation en cours */
  currentYear: TrainingYear | null;
  /** CGU acceptées dans leur dernière version */
  termsAccepted: boolean;
}

export interface RegisterRequest {
  firstName: string;
  lastName: string;
  email: string;
  speciality: Speciality;
  password: string;
}

/** Partenaire (sponsor) géré depuis l'administration. `null` = aucun partenaire affiché. */
export interface Partner {
  name: string;
  /** Logo envoyé depuis l'admin. Absent → logo de démonstration. */
  logoUrl?: string;
  tagline: string;
  about: string;
  url: string;
  points: { icon: 'bulb' | 'book' | 'users' | 'target'; label: string }[];
  /** AAAA-MM-JJ */
  startDate: string;
  endDate?: string;
}

/** Campagne publicitaire plein écran (`Marketing`). */
export interface Campaign {
  id: string;
  /** Visuel adapté à la largeur d'écran */
  imageUrl?: string;
  redirectUrl?: string;
  durationMs: number;
}

export interface Terms {
  publishedAt: string;
  content: string;
}

export interface Dashboard {
  user: UserProfile;
  year: YearStats;
  week: WeekSummary;
  streak: Streak;
  pendingCelebrations: Milestone[];
}
