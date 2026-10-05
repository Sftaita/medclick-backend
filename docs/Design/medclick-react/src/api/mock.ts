// API simulée en mémoire : permet de lancer l'app sans backend (VITE_USE_MOCK=true).
// Elle reproduit le comportement attendu du serveur, y compris la transaction
// atomique de l'encodage en lot et la règle « un milestone n'est célébré qu'une fois ».
import type { MedClickApi } from './types';
import type {
  BatchResponse, DayDetail, Milestone, Statistics, Surgery, WeekSummary, YearStats, Role,
} from '@/types';
import { ACTES, actesById, FAVORITE_ACTE_IDS } from '@/data/actes';
import { addDaysISO, localISO, todayISO } from '@/lib/format';

const LATENCY = 350;
const wait = <T,>(v: T, ms = LATENCY) => new Promise<T>((r) => setTimeout(() => r(structuredClone(v)), ms));
const uid = () => Math.random().toString(36).slice(2, 10);

// ---------- Seed (aligné sur la maquette : 124 interventions, 47 en 1re main) ----------
const MONTHLY = [8, 12, 10, 18, 14, 22, 16, 24];
const REGION_ACTES = { genou: ['lca', 'ptg', 'arthro-genou', 'menisc'], hanche: ['pth'], epaule: ['arthro-epaule'], autre: ['osteo-femur'] };
const REGION_PLAN: (keyof typeof REGION_ACTES)[] = [
  ...Array(52).fill('genou'), ...Array(35).fill('hanche'), ...Array(19).fill('epaule'), ...Array(18).fill('autre'),
];

function seed(): Surgery[] {
  const out: Surgery[] = [];
  const now = new Date();
  let i = 0;
  MONTHLY.forEach((count, m) => {
    const monthStart = new Date(now.getFullYear(), now.getMonth() - (MONTHLY.length - 1 - m), 1, 12);
    for (let k = 0; k < count; k++, i++) {
      let d = new Date(monthStart.getTime() + ((k * 3) % 27) * 86_400_000);
      if (d > now) d = new Date(now);
      const region = REGION_PLAN[(i * 37) % REGION_PLAN.length];
      const list = REGION_ACTES[region];
      const role: Role = (i * 7) % 124 < 47 ? 'FIRST_HAND' : i % 3 ? 'ASSISTANT' : 'OBSERVER';
      out.push({
        id: uid(), acteId: list[i % list.length], date: localISO(d), role,
        surgeon: 'Dr De Muylder', trainingYear: 3, createdAt: d.toISOString(),
      });
    }
  });
  return out.sort((a, b) => b.date.localeCompare(a.date));
}

const db = {
  surgeries: seed(),
  favorites: new Set(FAVORITE_ACTE_IDS),
  consultations: 18,
  guards: 12,
  previous: { interventions: 111, firstHand: 44, consultations: 15, completionMonthStart: 61 },
  completionTarget: 172,
  celebrated: new Map<string, string>(),
  achievedAt: new Map<string, string>(),
  weekIncompleteTuesday: true,
  streak: { weeks: 6, acknowledgedWeeks: 6 },
  addedThisWeek: 0,
};

const pctDelta = (cur: number, prev: number | null) => (prev ? Math.round(((cur - prev) / prev) * 100) : null);

function yearStats(): YearStats {
  const interventions = db.surgeries.length;
  const firstHand = db.surgeries.filter((s) => s.role === 'FIRST_HAND').length;
  const completion = Math.min(100, Math.round((interventions / db.completionTarget) * 100));
  return {
    interventions, firstHand, consultations: db.consultations, guards: db.guards,
    completion, completionTarget: db.completionTarget,
    deltas: {
      interventions: pctDelta(interventions, db.previous.interventions),
      firstHand: pctDelta(firstHand, db.previous.firstHand),
      consultations: pctDelta(db.consultations, db.previous.consultations),
      completionThisMonth: completion - db.previous.completionMonthStart,
    },
  };
}

type Def = Omit<Milestone, 'progress' | 'achievedAt' | 'celebratedAt'>;
const MILESTONES: Def[] = [
  { id: 'int-100', title: '100 interventions', subtitle: 'Première étape atteinte !', icon: 'trophy', metric: 'interventions', threshold: 100 },
  { id: 'fh-40', title: '40 en première main', subtitle: 'Autonomie croissante', icon: 'trophy', metric: 'firstHand', threshold: 40 },
  { id: 'fh-50', title: '50 interventions en première main', subtitle: 'Belle progression cette année !', icon: 'trophy', metric: 'firstHand', threshold: 50 },
  { id: 'lca-fh', title: 'Première LCA', subtitle: 'en première main', icon: 'joint', metric: 'custom', threshold: 1 },
  { id: 'pth-as', title: 'Première PTH', subtitle: 'en assistance', icon: 'hip', metric: 'custom', threshold: 1 },
  { id: 'cons-20', title: '20 consultations', subtitle: 'Réalisez 20 consultations', icon: 'stetho', metric: 'consultations', threshold: 20 },
  { id: 'guard-1', title: 'Participer à une garde', subtitle: 'Validez au moins une garde', icon: 'moon', metric: 'guards', threshold: 1 },
  { id: 'int-150', title: '150 interventions', subtitle: 'Cap des 150', icon: 'trophy', metric: 'interventions', threshold: 150 },
  { id: 'int-200', title: '200 interventions', subtitle: 'Cap des 200', icon: 'trophy', metric: 'interventions', threshold: 200 },
  { id: 'fh-100', title: '100 en première main', subtitle: 'Autonomie confirmée', icon: 'hand', metric: 'firstHand', threshold: 100 },
  { id: 'comp-80', title: 'Carnet à 80 %', subtitle: 'Bientôt au bout', icon: 'trophy', metric: 'completion', threshold: 80 },
  { id: 'comp-100', title: 'Carnet complet', subtitle: '100 % du carnet validé', icon: 'trophy', metric: 'completion', threshold: 100 },
];

function metricValue(def: Def, y: YearStats): number {
  switch (def.metric) {
    case 'interventions': return y.interventions;
    case 'firstHand': return y.firstHand;
    case 'consultations': return y.consultations;
    case 'guards': return y.guards;
    case 'completion': return y.completion;
    default:
      if (def.id === 'lca-fh') return db.surgeries.some((s) => s.acteId === 'lca' && s.role === 'FIRST_HAND') ? 1 : 0;
      if (def.id === 'pth-as') return db.surgeries.some((s) => s.acteId === 'pth' && s.role === 'ASSISTANT') ? 1 : 0;
      return 0;
  }
}

function milestones(): Milestone[] {
  const y = yearStats();
  return MILESTONES.map((d) => {
    const v = metricValue(d, y);
    const achieved = v >= d.threshold;
    if (achieved && !db.achievedAt.has(d.id)) db.achievedAt.set(d.id, new Date().toISOString());
    return {
      ...d, progress: Math.min(v, d.threshold),
      achievedAt: achieved ? db.achievedAt.get(d.id)! : null,
      celebratedAt: achieved ? db.celebrated.get(d.id) ?? null : null,
    };
  });
}
// Au démarrage, tout ce qui est déjà atteint est considéré comme déjà célébré.
milestones().forEach((m) => m.achievedAt && db.celebrated.set(m.id, m.achievedAt));

function mondayOf(iso: string) {
  const d = new Date(iso + 'T12:00:00');
  const dow = (d.getDay() + 6) % 7;
  return addDaysISO(iso, -dow);
}

function week(offset = 0): WeekSummary {
  const start = addDaysISO(mondayOf(todayISO()), offset * 7);
  const names = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
  const summaries = ['3 interventions · 1 consultation', 'Matin : 2 interventions', '2 interventions · 1 garde', '3 interventions', '2 consultations'];
  const days = names.map((n, i) => {
    const date = addDaysISO(start, i);
    const label = `${n} ${new Date(date + 'T12:00:00').toLocaleDateString('fr-BE', { day: 'numeric', month: 'short' })}`;
    const tueBroken = i === 1 && db.weekIncompleteTuesday && offset === 0;
    return {
      date, label,
      status: tueBroken ? 'incomplete' as const : 'complete' as const,
      summary: i === 1 && !tueBroken ? 'Matin : 2 interventions · Après-midi : 1 intervention' : summaries[i],
      missing: tueBroken ? 'Après-midi : non complété' : undefined,
    };
  });
  const incompleteDays = days.filter((d) => d.status === 'incomplete').length;
  const end = addDaysISO(start, 6);
  const fmt = (d: string) => new Date(d + 'T12:00:00').toLocaleDateString('fr-BE', { weekday: 'short', day: 'numeric', month: 'short' });
  return {
    start, end, label: `${fmt(start)} – ${fmt(end)}`,
    interventions: 12 + db.addedThisWeek, firstHand: 6, consultations: 3, guards: 1,
    completion: incompleteDays ? 94 : 100, days, incompleteDays, streak: { ...db.streak },
  };
}

export const mockApi: MedClickApi = {
  login: async () => wait({ firstName: 'Samy', lastName: 'Ben Ali', email: 'samy.benali@chu.be', trainingYear: 3, hospital: 'CHU Saint-Pierre' }),

  getDashboard: async () => wait({
    user: { firstName: 'Samy', lastName: 'Ben Ali', email: 'samy.benali@chu.be', trainingYear: 3, hospital: 'CHU Saint-Pierre' },
    year: yearStats(),
    week: week(0),
    streak: { ...db.streak },
    partner: await mockApi.getPartner(),
    pendingCelebrations: milestones().filter((m) => m.achievedAt && !m.celebratedAt),
  }),

  listSurgeries: async (f = {}) => {
    let list = db.surgeries;
    if (f.region) list = list.filter((s) => actesById[s.acteId]?.region === f.region);
    if (f.q) {
      const q = f.q.toLowerCase();
      list = list.filter((s) => (actesById[s.acteId]?.label + ' ' + actesById[s.acteId]?.fullName).toLowerCase().includes(q));
    }
    return wait(list, 200);
  },
  getSurgery: async (id) => {
    const s = db.surgeries.find((x) => x.id === id);
    if (!s) throw new Error('Intervention introuvable');
    return wait(s, 150);
  },
  updateSurgery: async (id, patch) => {
    const s = db.surgeries.find((x) => x.id === id);
    if (!s) throw new Error('Intervention introuvable');
    Object.assign(s, patch);
    return wait(s);
  },
  deleteSurgery: async (id) => {
    db.surgeries = db.surgeries.filter((s) => s.id !== id);
    return wait(undefined, 200);
  },

  createBatch: async (req) => {
    // 1) Validation complète AVANT toute écriture (équivalent d'une transaction : tout ou rien).
    if (!req.lines.length) throw new Error('Aucune intervention à enregistrer');
    for (const l of req.lines) {
      if (!actesById[l.acteId]) throw new Error(`Acte inconnu : ${l.acteId}`);
      if (l.quantity < 1 || l.quantity > 20 || l.roles.length !== l.quantity) throw new Error('Quantité ou rôles invalides');
    }
    const before = yearStats();
    const beforeMs = milestones();
    // 2) Écriture : N enregistrements Surgery distincts.
    const created: Surgery[] = req.lines.flatMap((l) => l.roles.map((role) => ({
      id: uid(), acteId: l.acteId, role, date: req.common.date, surgeon: req.common.surgeon,
      trainingYear: req.common.trainingYear, nomenclature: req.common.nomenclature, createdAt: new Date().toISOString(),
    })));
    db.surgeries = [...created, ...db.surgeries];
    if (req.common.date >= mondayOf(todayISO())) db.addedThisWeek += created.length;
    // Journée incomplète de la maquette : un ajout ce mardi-là la complète.
    const tue = addDaysISO(mondayOf(todayISO()), 1);
    if (db.weekIncompleteTuesday && req.common.date === tue) {
      db.weekIncompleteTuesday = false;
      db.streak.weeks += 1;
    }
    const after = yearStats();
    const newlyAchieved = milestones().filter(
      (m) => m.achievedAt && !m.celebratedAt && !beforeMs.find((b) => b.id === m.id)?.achievedAt,
    );
    const res: BatchResponse = { created, before, after, newlyAchieved };
    return wait(res);
  },

  getFavoriteActeIds: async () => wait([...db.favorites], 100),
  setFavoriteActe: async (id, fav) => { fav ? db.favorites.add(id) : db.favorites.delete(id); return wait(undefined, 100); },

  getWeek: async (offset = 0) => wait(week(offset)),
  getDay: async (date) => {
    const tue = addDaysISO(mondayOf(todayISO()), 1);
    const afternoonBroken = date === tue && db.weekIncompleteTuesday;
    const added = db.surgeries.filter((s) => s.date === date).slice(0, 3);
    const d: DayDetail = {
      date, label: new Date(date + 'T12:00:00').toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
      morning: [
        { id: 'm1', title: 'PTG', role: 'FIRST_HAND', time: '08h15', kind: 'surgery' },
        { id: 'm2', title: 'Consultation', role: 'OBSERVER', time: '10h30', kind: 'consultation' },
      ],
      afternoon: afternoonBroken ? [] : added.map((s) => ({ id: s.id, title: actesById[s.acteId].label, role: s.role, kind: 'surgery' as const })),
      guard: null,
      morningComplete: true,
      afternoonComplete: !afternoonBroken,
    };
    return wait(d, 200);
  },

  getMilestones: async () => wait(milestones(), 200),
  markMilestonesCelebrated: async (ids) => { ids.forEach((id) => db.celebrated.set(id, new Date().toISOString())); return wait(undefined, 50); },
  acknowledgeStreak: async (weeks) => { db.streak.acknowledgedWeeks = weeks; return wait(undefined, 50); },

  getStatistics: async () => {
    const y = yearStats();
    const now = new Date();
    const monthly = MONTHLY.map((_, i) => {
      const d = new Date(now.getFullYear(), now.getMonth() - (MONTHLY.length - 1 - i), 1);
      const key = localISO(d).slice(0, 7);
      return {
        month: d.toLocaleDateString('fr-BE', { month: 'short' }).replace('.', ''),
        count: db.surgeries.filter((s) => s.date.slice(0, 7) === key).length,
      };
    });
    const regions = ['genou', 'hanche', 'epaule', 'autre'] as const;
    const names = { genou: 'Genou', hanche: 'Hanche', epaule: 'Épaule', autre: 'Autres' };
    const total = db.surgeries.length || 1;
    const s: Statistics = {
      periodLabel: `Cette année (${now.getFullYear() - 1} – ${now.getFullYear()})`,
      year: y, guardsDelta: 0, monthly,
      byRegion: regions.map((r) => ({
        region: names[r],
        percent: Math.round((db.surgeries.filter((x) => actesById[x.acteId]?.region === r).length / total) * 100),
      })),
    };
    return wait(s);
  },
  getProfile: async () => wait({ firstName: 'Samy', lastName: 'Ben Ali', email: 'samy.benali@chu.be', trainingYear: 3, hospital: 'CHU Saint-Pierre' }, 150),
  getPartner: async () => wait({
    name: 'OrthoNova', tagline: "Innover aujourd'hui pour mieux soigner demain",
    about: "OrthoNova est un partenaire engagé aux côtés des internes en orthopédie, pour soutenir la formation, l'innovation et l'excellence chirurgicale.",
    url: 'https://example.com',
    points: [
      { icon: 'bulb', label: 'Solutions innovantes' },
      { icon: 'book', label: 'Formation continue' },
      { icon: 'users', label: 'Soutien aux jeunes chirurgiens' },
      { icon: 'target', label: 'Une chirurgie plus durable' },
    ],
  }, 0),
};

export const MOCK_ACTES = ACTES;
