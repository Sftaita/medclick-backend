import type {
  Acte, BatchRequest, BatchResponse, Campaign, Consultation, Dashboard, DayDetail, Favorite, Formation, Garde,
  Milestone, OrthoType, Partner, Region, RegisterRequest, Speciality, Statistics, Surgeon, Surgery, Terms,
  TrainingYear, UserProfile, WeekSummary,
} from '@/types';

export type ResetResult = 'ok' | 'expired';
export type NewOf<T extends { id: string }> = Omit<T, 'id'>;

/**
 * Contrat unique entre l'UI et le backend.
 * Deux implémentations : `httpApi` (backend Symfony, routes existantes + routes à créer) et `mockApi` (démo en mémoire).
 * La correspondance avec chaque route est décrite dans docs/API.md.
 */
export interface MedClickApi {
  /* Compte */
  login(email: string, password: string): Promise<UserProfile>;
  logout(): Promise<void>;
  register(req: RegisterRequest): Promise<void>;
  /** Réponse identique que l'adresse existe ou non */
  forgotPassword(email: string): Promise<void>;
  resetPassword(email: string, token: string, password: string): Promise<ResetResult>;
  getProfile(): Promise<UserProfile>;
  getTerms(): Promise<Terms>;
  acceptTerms(): Promise<void>;

  /* Contenus gérés par l'administration */
  /** Partenaire affiché, ou null s'il n'y en a pas (ou hors période) */
  getPartner(): Promise<Partner | null>;
  savePartner(p: Partner | null): Promise<void>;
  /** Campagne plein écran affichée après la connexion, ou null */
  getCampaign(): Promise<Campaign | null>;
  registerCampaignClick(id: string): Promise<void>;

  /* Tableau de bord */
  getDashboard(): Promise<Dashboard>;
  getWeek(offset?: number): Promise<WeekSummary>;
  getDay(date: string): Promise<DayDetail>;
  getMilestones(): Promise<Milestone[]>;
  markMilestonesCelebrated(ids: string[]): Promise<void>;
  acknowledgeStreak(weeks: number): Promise<void>;
  getStatistics(): Promise<Statistics>;

  /* Années, chirurgiens */
  listYears(): Promise<TrainingYear[]>;
  saveYear(y: NewOf<TrainingYear> & { id?: string }): Promise<TrainingYear>;
  /** Télécharge le carnet de stage officiel (.xlsx) */
  exportLogbook(yearId: string): Promise<void>;
  listSurgeons(yearId: string): Promise<Surgeon[]>;
  saveSurgeon(s: NewOf<Surgeon> & { id?: string }): Promise<Surgeon>;
  /** Supprime aussi les interventions liées (règle du backend) */
  deleteSurgeon(id: string): Promise<void>;

  /* Nomenclature, favoris */
  searchNomenclature(f: { speciality: Speciality; orthoType?: OrthoType; region?: Region; q?: string }): Promise<Acte[]>;
  getActe(id: string): Promise<Acte>;
  listFavorites(): Promise<Favorite[]>;
  addFavorite(acteId: string, shortcut: string): Promise<Favorite>;
  renameFavorite(id: string, shortcut: string): Promise<void>;
  removeFavorite(id: string): Promise<void>;

  /* Interventions */
  listSurgeries(filter?: { speciality?: Speciality; q?: string }): Promise<Surgery[]>;
  getSurgery(id: string): Promise<Surgery>;
  updateSurgery(id: string, patch: Partial<Omit<Surgery, 'id' | 'createdAt'>>): Promise<Surgery>;
  deleteSurgery(id: string): Promise<void>;
  /** Enregistrement atomique : N interventions distinctes dans UNE transaction (route à créer). */
  createBatch(req: BatchRequest): Promise<BatchResponse>;

  /* Consultations, gardes, formations */
  listConsultations(): Promise<Consultation[]>;
  saveConsultation(c: NewOf<Consultation> & { id?: string }): Promise<Consultation>;
  listGardes(): Promise<Garde[]>;
  saveGarde(g: NewOf<Garde> & { id?: string }): Promise<Garde>;
  listFormations(): Promise<Formation[]>;
  saveFormation(f: NewOf<Formation> & { id?: string }): Promise<Formation>;
}
