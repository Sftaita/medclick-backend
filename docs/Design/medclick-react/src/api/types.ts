import type {
  BatchRequest, BatchResponse, Dashboard, DayDetail, Milestone, Partner,
  Statistics, Surgery, UserProfile, WeekSummary, Region,
} from '@/types';

/**
 * Contrat unique entre l'UI et le backend.
 * Deux implémentations : `httpApi` (vrai backend) et `mockApi` (démo en mémoire).
 */
export interface MedClickApi {
  login(email: string, password: string): Promise<UserProfile>;
  getDashboard(): Promise<Dashboard>;

  listSurgeries(filter?: { region?: Region; q?: string }): Promise<Surgery[]>;
  getSurgery(id: string): Promise<Surgery>;
  updateSurgery(id: string, patch: Partial<Omit<Surgery, 'id' | 'createdAt'>>): Promise<Surgery>;
  deleteSurgery(id: string): Promise<void>;

  /** Enregistrement atomique : crée N interventions distinctes dans UNE transaction. */
  createBatch(req: BatchRequest): Promise<BatchResponse>;

  getFavoriteActeIds(): Promise<string[]>;
  setFavoriteActe(acteId: string, favorite: boolean): Promise<void>;

  getWeek(offset?: number): Promise<WeekSummary>;
  getDay(date: string): Promise<DayDetail>;

  getMilestones(): Promise<Milestone[]>;
  /** À appeler après avoir montré la célébration → elle ne se répète jamais. */
  markMilestonesCelebrated(ids: string[]): Promise<void>;
  acknowledgeStreak(weeks: number): Promise<void>;

  getStatistics(): Promise<Statistics>;
  getProfile(): Promise<UserProfile>;
  getPartner(): Promise<Partner>;
}
