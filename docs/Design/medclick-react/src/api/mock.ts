// API simulée en mémoire : permet de lancer l'app sans backend (VITE_USE_MOCK=true).
// Elle reproduit les règles du serveur réel (rôles 1/3/2, superviseur obligatoire selon le rôle,
// chirurgien supprimé avec ses interventions, réponse identique au « mot de passe oublié »…)
// et celles des routes encore à créer (lot atomique, milestones célébrés une seule fois).
import type { MedClickApi } from './types';
import type {
  Acte, BatchResponse, Campaign, Consultation, DayDetail, DayStatus, Favorite, Formation, Garde, Milestone, Partner,
  Role, Statistics, Surgeon, Surgery, TrainingYear, UserProfile, WeekSummary, YearStats,
} from '@/types';
import { DEMO_NOMENCLATURE, nomenclatureById } from '@/data/referentiel';
import { addDaysISO, localISO, todayISO } from '@/lib/format';

const LATENCY = 300;
const wait = <T,>(v: T, ms = LATENCY) => new Promise<T>((r) => setTimeout(() => r(structuredClone(v)), ms));
const uid = () => Math.random().toString(36).slice(2, 10);
const fail = (message: string) => Promise.reject(new Error(message));

/* ------------------------------------------------------------------ Données */
const YEARS: TrainingYear[] = [
  { id: 'y3', yearOfFormation: 3, dateOfStart: '2025-09-01', hospital: 'CHU Saint-Pierre', master: 'Dr De Muylder' },
  { id: 'y2', yearOfFormation: 2, dateOfStart: '2024-09-01', hospital: 'CHU Saint-Pierre', master: 'Dr De Muylder' },
  { id: 'y1', yearOfFormation: 1, dateOfStart: '2023-09-01', hospital: 'CHU Saint-Pierre', master: 'Dr De Muylder' },
];
const CURRENT_YEAR = 'y3';

const SURGEONS: Surgeon[] = [
  { id: 's1', firstName: 'Marc', lastName: 'De Muylder', yearId: 'y3', boss: true },
  { id: 's2', firstName: 'Anne', lastName: 'Lambert', yearId: 'y3', boss: false },
  { id: 's3', firstName: 'Jan', lastName: 'Peeters', yearId: 'y3', boss: false },
];

const FAVORITES = (): Favorite[] => [
  { id: 'f1', shortcut: 'LCA', acte: nomenclatureById['n-lca'] },
  { id: 'f2', shortcut: 'PTG', acte: nomenclatureById['n-ptg'] },
  { id: 'f3', shortcut: 'PTH', acte: nomenclatureById['n-pth'] },
  { id: 'f4', shortcut: 'Arthroscopie épaule', acte: nomenclatureById['n-arthro-ep'] },
  { id: 'f5', shortcut: 'Arthrodèse lombaire', acte: nomenclatureById['n-arthrodese'] },
];

// 124 interventions, dont 47 en 1re main (solo ou aidée), sur 8 mois.
const MONTHLY = [8, 12, 10, 18, 14, 22, 16, 24];
const ACTE_PLAN = ['n-lca', 'n-ptg', 'n-pth', 'n-menisc', 'n-arthro-ep', 'n-radius', 'n-clou', 'n-arthrodese', 'n-hallux', 'n-cheville', 'n-chole'];

function seedSurgeries(): Surgery[] {
  const out: Surgery[] = [];
  const now = new Date();
  const today = todayISO();
  const skip = addDaysISO(today, -2); // un jour ouvré récent laissé vide pour la démo de « Ma semaine »
  let i = 0;
  MONTHLY.forEach((count, m) => {
    const monthStart = new Date(now.getFullYear(), now.getMonth() - (MONTHLY.length - 1 - m), 1, 12);
    for (let k = 0; k < count; k++, i++) {
      let d = new Date(monthStart.getTime() + ((k * 3) % 27) * 86_400_000);
      if (d > now) d = new Date(now.getTime() - (k % 5) * 86_400_000);
      let iso = localISO(d);
      if (iso === skip) iso = addDaysISO(iso, -1);
      const r = (i * 7) % 124;
      const role: Role = r < 30 ? 'SOLO' : r < 47 ? 'ASSISTED' : 'SECOND';
      out.push({
        id: uid(), acte: nomenclatureById[ACTE_PLAN[(i * 5) % ACTE_PLAN.length]], date: iso, role,
        supervisorId: role === 'SOLO' ? undefined : i % 3 ? 's1' : 's2',
        yearId: CURRENT_YEAR, createdAt: d.toISOString(),
      });
    }
  });
  return out.sort((a, b) => b.date.localeCompare(a.date));
}

function seedConsultations(): Consultation[] {
  const parts = ['morning', 'afternoon', 'morning', 'night'] as const;
  const specs = ['ortho', 'traumato', 'ortho', 'ortho'] as const;
  return Array.from({ length: 18 }, (_, i) => ({
    id: uid(), date: addDaysISO(todayISO(), -(i * 9 + 3)), dayPart: parts[i % 4], speciality: specs[i % 4],
    number: [12, 9, 15, 4][i % 4], yearId: CURRENT_YEAR,
  }));
}

function seedGardes(): Garde[] {
  return Array.from({ length: 12 }, (_, i) => {
    const start = addDaysISO(todayISO(), -(i * 11 + 4));
    const day = i % 3 === 1;
    return {
      id: uid(), dateOfStart: `${start}T${day ? '08:00' : '20:00'}`,
      dateOfEnd: day ? `${start}T20:00` : `${addDaysISO(start, 1)}T08:00`, number: [7, 11, 5, 6][i % 4], yearId: CURRENT_YEAR,
    };
  });
}

const FORMATIONS: Formation[] = [
  { id: 'fo1', event: 'congres', name: "Congrès annuel d'orthopédie", description: 'Résultats à deux ans des plasties du LCA', dateOfStart: '2026-11-19T08:30', dateOfEnd: '2026-11-20T17:30', local: false, location: 'Bruxelles', role: 'speaker', yearId: CURRENT_YEAR },
  { id: 'fo2', event: 'staff', name: 'Staff du service', dateOfStart: '2026-10-02T07:30', dateOfEnd: '2026-10-02T08:30', local: true, role: 'participant', yearId: CURRENT_YEAR },
  { id: 'fo3', event: 'journal', name: 'Journal club', description: 'Analyse critique d\'un article', dateOfStart: '2026-09-17T12:30', dateOfEnd: '2026-09-17T13:30', local: true, role: 'organiser', yearId: CURRENT_YEAR },
];

const DEMO_PARTNER: Partner = {
  name: 'OrthoNova', tagline: "Innover aujourd'hui pour mieux soigner demain",
  about: "Un partenaire engagé aux côtés des médecins assistants en chirurgie, pour soutenir la formation, l'innovation et l'excellence chirurgicale.",
  url: 'https://example.com',
  points: [
    { icon: 'bulb', label: 'Solutions innovantes' },
    { icon: 'book', label: 'Formation continue' },
    { icon: 'users', label: 'Soutien aux jeunes chirurgiens' },
    { icon: 'target', label: 'Une chirurgie plus durable' },
  ],
  startDate: '2026-09-01', endDate: '2027-08-31',
};

const db = {
  years: YEARS,
  surgeons: SURGEONS,
  favorites: FAVORITES(),
  surgeries: seedSurgeries(),
  consultations: seedConsultations(),
  gardes: seedGardes(),
  formations: FORMATIONS,
  partner: DEMO_PARTNER as Partner | null,
  // Mémorisé dans le navigateur pour que la démo ne redemande pas les CGU à chaque rechargement.
  termsAccepted: (() => { try { return localStorage.getItem('mc.mock.terms') === '1'; } catch { return false; } })(),
  previous: { interventions: 111, firstHand: 44, consultations: 15, completionMonthStart: 61 },
  completionTarget: 172,
  celebrated: new Map<string, string>(),
  achievedAt: new Map<string, string>(),
  streak: { weeks: 6, acknowledgedWeeks: 6 },
};

const profile = (): UserProfile => ({
  firstName: 'Samy', lastName: 'Ben Ali', email: 'samy.benali@chu.be', speciality: 'ortho',
  currentYear: db.years.find((y) => y.id === CURRENT_YEAR) ?? null, termsAccepted: db.termsAccepted,
});

/* ------------------------------------------------------------- Statistiques */
const pctDelta = (cur: number, prev: number | null) => (prev ? Math.round(((cur - prev) / prev) * 100) : null);
const isFirstHand = (r: Role) => r === 'SOLO' || r === 'ASSISTED';

function yearStats(): YearStats {
  const s = db.surgeries.filter((x) => x.yearId === CURRENT_YEAR);
  const interventions = s.length;
  const firstHand = s.filter((x) => isFirstHand(x.role)).length;
  const completion = Math.min(100, Math.round((interventions / db.completionTarget) * 100));
  return {
    interventions, firstHand, consultations: db.consultations.length, guards: db.gardes.length,
    formations: db.formations.length, completion, completionTarget: db.completionTarget,
    deltas: {
      interventions: pctDelta(interventions, db.previous.interventions),
      firstHand: pctDelta(firstHand, db.previous.firstHand),
      consultations: pctDelta(db.consultations.length, db.previous.consultations),
      completionThisMonth: completion - db.previous.completionMonthStart,
    },
  };
}

type Def = Omit<Milestone, 'progress' | 'achievedAt' | 'celebratedAt'>;
const MILESTONES: Def[] = [
  { id: 'int-100', title: '100 interventions', subtitle: 'Première étape atteinte !', icon: 'trophy', metric: 'interventions', threshold: 100 },
  { id: 'fh-40', title: '40 en première main', subtitle: 'Autonomie croissante', icon: 'trophy', metric: 'firstHand', threshold: 40 },
  { id: 'fh-50', title: '50 interventions en première main', subtitle: 'Belle progression cette année !', icon: 'trophy', metric: 'firstHand', threshold: 50 },
  { id: 'lca-fh', title: 'Première LCA', subtitle: 'en première main', icon: 'knee', metric: 'custom', threshold: 1 },
  { id: 'pth-2', title: 'Première PTH', subtitle: 'en 2e main', icon: 'hip', metric: 'custom', threshold: 1 },
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
      if (def.id === 'lca-fh') return db.surgeries.some((s) => s.acte.id === 'n-lca' && isFirstHand(s.role)) ? 1 : 0;
      if (def.id === 'pth-2') return db.surgeries.some((s) => s.acte.id === 'n-pth' && s.role === 'SECOND') ? 1 : 0;
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

/* ------------------------------------------------------------ Semaine, jour */
function mondayOf(iso: string) {
  const d = new Date(iso + 'T12:00:00');
  return addDaysISO(iso, -((d.getDay() + 6) % 7));
}
const fmtDay = (iso: string, o: Intl.DateTimeFormatOptions) => new Date(iso + 'T12:00:00').toLocaleDateString('fr-BE', o);
const plural = (n: number, one: string, many = one + 's') => `${n} ${n > 1 ? many : one}`;

function activitiesOn(date: string) {
  return {
    surgeries: db.surgeries.filter((s) => s.date === date),
    consultations: db.consultations.filter((c) => c.date === date),
    garde: db.gardes.find((g) => g.dateOfStart.slice(0, 10) === date) ?? null,
  };
}

function week(offset = 0): WeekSummary {
  const today = todayISO();
  const start = addDaysISO(mondayOf(today), offset * 7);
  const names = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
  const days: DayStatus[] = names.map((n, i) => {
    const date = addDaysISO(start, i);
    const a = activitiesOn(date);
    const parts = [
      a.surgeries.length && plural(a.surgeries.length, 'intervention'),
      a.consultations.length && plural(a.consultations.length, 'consultation'),
      a.garde && '1 garde',
    ].filter(Boolean) as string[];
    const status = date > today ? 'future' : parts.length ? 'complete' : 'empty';
    return {
      date, label: `${n} ${fmtDay(date, { day: 'numeric', month: 'short' })}`, status,
      summary: status === 'future' ? 'À venir' : parts.join(' · ') || 'Aucune activité encodée',
    };
  });
  const elapsed = days.filter((d) => d.status !== 'future');
  const incompleteDays = elapsed.filter((d) => d.status === 'empty').length;
  const inWeek = <T,>(list: T[], date: (x: T) => string) => list.filter((x) => date(x) >= start && date(x) <= addDaysISO(start, 6));
  const ws = inWeek(db.surgeries, (s) => s.date);
  const end = addDaysISO(start, 6);
  return {
    start, end, label: `${fmtDay(start, { weekday: 'short', day: 'numeric', month: 'short' })} – ${fmtDay(end, { weekday: 'short', day: 'numeric', month: 'short' })}`,
    interventions: ws.length, firstHand: ws.filter((s) => isFirstHand(s.role)).length,
    consultations: inWeek(db.consultations, (c) => c.date).length,
    guards: inWeek(db.gardes, (g) => g.dateOfStart.slice(0, 10)).length,
    completion: elapsed.length ? Math.round(((elapsed.length - incompleteDays) / elapsed.length) * 100) : 100,
    days, incompleteDays, streak: { ...db.streak },
  };
}

/* ---------------------------------------------------------------- Helpers */
function upsert<T extends { id: string }>(list: T[], item: Omit<T, 'id'> & { id?: string }): T {
  if (item.id) {
    const i = list.findIndex((x) => x.id === item.id);
    if (i < 0) throw new Error('Élément introuvable');
    list[i] = { ...list[i], ...item } as T;
    return list[i];
  }
  const created = { ...item, id: uid() } as T;
  list.unshift(created);
  return created;
}

function partnerActive(): Partner | null {
  const p = db.partner;
  if (!p) return null;
  const t = todayISO();
  if (t < p.startDate || (p.endDate && t > p.endDate)) return null;
  return p;
}

/* ------------------------------------------------------------------- API */
export const mockApi: MedClickApi = {
  login: async (email) => {
    if (email.includes('inactif')) return fail("Compte non activé : vérifiez l'e-mail d'activation.");
    return wait(profile());
  },
  logout: async () => wait(undefined, 50),
  register: async (req) => {
    if (req.password.length < 6 || req.password.length > 50) return fail('Le mot de passe doit contenir entre 6 et 50 caractères.');
    return wait(undefined);
  },
  forgotPassword: async () => wait(undefined),
  resetPassword: async (_email, token) => wait(token === 'expire' ? 'expired' as const : 'ok' as const),
  getProfile: async () => wait(profile(), 120),
  getTerms: async () => wait({ publishedAt: '2026-09-15', content: '[Texte des conditions d\'utilisation publié depuis l\'administration]' }, 120),
  acceptTerms: async () => {
    db.termsAccepted = true;
    try { localStorage.setItem('mc.mock.terms', '1'); } catch { /* stockage indisponible */ }
    return wait(undefined, 120);
  },

  getPartner: async () => wait(partnerActive(), 0),
  savePartner: async (p) => { db.partner = p; return wait(undefined); },
  getCampaign: async (): Promise<Campaign | null> => wait({ id: 'c1', durationMs: 4000, redirectUrl: 'https://example.com' }, 100),
  registerCampaignClick: async () => wait(undefined, 50),

  getDashboard: async () => wait({
    user: profile(), year: yearStats(), week: week(0), streak: { ...db.streak },
    pendingCelebrations: milestones().filter((m) => m.achievedAt && !m.celebratedAt),
  }),
  getWeek: async (offset = 0) => wait(week(offset)),
  getDay: async (date) => {
    const a = activitiesOn(date);
    const d: DayDetail = {
      date, label: fmtDay(date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
      surgeries: a.surgeries,
      consultations: Object.fromEntries(a.consultations.map((c) => [c.dayPart, c])),
      garde: a.garde,
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
      return { month: d.toLocaleDateString('fr-BE', { month: 'short' }).replace('.', ''), count: db.surgeries.filter((s) => s.date.slice(0, 7) === key).length };
    });
    const total = db.surgeries.length || 1;
    const groups: { label: string; test: (a: Acte) => boolean }[] = [
      { label: 'Ortho électif', test: (a) => a.speciality === 'ortho' && a.orthoType !== 'trauma' },
      { label: 'Traumatologie', test: (a) => a.speciality === 'ortho' && a.orthoType === 'trauma' },
      { label: 'Digestif', test: (a) => a.speciality === 'dig' },
      { label: 'Autres', test: (a) => !['ortho', 'dig'].includes(a.speciality) },
    ];
    const s: Statistics = {
      periodLabel: `3e année · ${now.getFullYear() - 1} – ${now.getFullYear()}`, year: y, guardsDelta: 0, monthly,
      bySpeciality: groups.map((g) => ({
        label: g.label,
        percent: Math.round((db.surgeries.filter((x) => g.test(x.acte)).length / total) * 100),
      })),
    };
    return wait(s);
  },

  listYears: async () => wait(db.years, 150),
  saveYear: async (y) => {
    if (db.years.some((x) => x.yearOfFormation === y.yearOfFormation && x.id !== y.id)) return fail('Cette année est déjà enregistrée.');
    return wait(upsert(db.years, y));
  },
  exportLogbook: async () => wait(undefined, 800),
  listSurgeons: async (yearId) => wait(db.surgeons.filter((s) => s.yearId === yearId), 150),
  saveSurgeon: async (s) => {
    if (s.boss) db.surgeons.forEach((x) => { if (x.yearId === s.yearId && x.id !== s.id) x.boss = false; });
    return wait(upsert(db.surgeons, s));
  },
  deleteSurgeon: async (id) => {
    db.surgeons = db.surgeons.filter((s) => s.id !== id);
    db.surgeries = db.surgeries.filter((s) => s.supervisorId !== id);
    return wait(undefined);
  },

  searchNomenclature: async (f) => {
    const q = f.q?.trim().toLowerCase();
    return wait(DEMO_NOMENCLATURE.filter((a) => a.speciality === f.speciality
      && (!f.orthoType || a.orthoType === f.orthoType)
      && (!f.region || a.region === f.region)
      && (!q || a.name.toLowerCase().includes(q) || a.code?.includes(q))), 150);
  },
  getActe: async (id) => (nomenclatureById[id] ? wait(nomenclatureById[id], 0) : fail('Intervention inconnue')),
  listFavorites: async () => wait(db.favorites, 100),
  addFavorite: async (acteId, shortcut) => (nomenclatureById[acteId] ? wait(upsert(db.favorites, { acte: nomenclatureById[acteId], shortcut })) : fail('Intervention inconnue')),
  renameFavorite: async (id, shortcut) => { upsert(db.favorites, { id, shortcut } as Favorite); return wait(undefined, 100); },
  removeFavorite: async (id) => { db.favorites = db.favorites.filter((f) => f.id !== id); return wait(undefined, 100); },

  listSurgeries: async (f = {}) => {
    let list = db.surgeries;
    if (f.speciality) list = list.filter((s) => s.acte.speciality === f.speciality);
    if (f.q) {
      const q = f.q.toLowerCase();
      list = list.filter((s) => s.acte.name.toLowerCase().includes(q)
        || db.favorites.some((fav) => fav.acte.id === s.acte.id && fav.shortcut.toLowerCase().includes(q)));
    }
    return wait(list, 200);
  },
  getSurgery: async (id) => {
    const s = db.surgeries.find((x) => x.id === id);
    return s ? wait(s, 150) : fail('Intervention introuvable');
  },
  updateSurgery: async (id, patch) => {
    const s = db.surgeries.find((x) => x.id === id);
    if (!s) return fail('Intervention introuvable');
    const next = { ...s, ...patch };
    if (next.role !== 'SOLO' && !next.supervisorId) return fail('Indiquez le superviseur.');
    if (next.role === 'SOLO') next.supervisorId = undefined;
    Object.assign(s, next);
    return wait(s);
  },
  deleteSurgery: async (id) => { db.surgeries = db.surgeries.filter((s) => s.id !== id); return wait(undefined, 200); },

  createBatch: async (req) => {
    // 1) Validation complète AVANT toute écriture (équivalent d'une transaction : tout ou rien).
    if (!req.lines.length) return fail('Aucune intervention à enregistrer');
    for (const l of req.lines) {
      if (!nomenclatureById[l.acteId]) return fail(`Intervention inconnue : ${l.acteId}`);
      if (l.quantity < 1 || l.quantity > 20 || l.roles.length !== l.quantity) return fail('Quantité ou rôles invalides');
      if (l.roles.some((r) => r !== 'SOLO') && !req.common.supervisorId) return fail('Indiquez le superviseur.');
    }
    if (req.common.date > todayISO()) return fail('La date ne peut pas être dans le futur.');
    const before = yearStats();
    const beforeMs = milestones();
    // 2) Écriture : N enregistrements Surgery distincts.
    const created: Surgery[] = req.lines.flatMap((l) => l.roles.map((role) => ({
      id: uid(), acte: nomenclatureById[l.acteId], role, date: req.common.date, yearId: req.common.yearId,
      supervisorId: role === 'SOLO' ? undefined : req.common.supervisorId, createdAt: new Date().toISOString(),
    })));
    db.surgeries = [...created, ...db.surgeries];
    const after = yearStats();
    const newlyAchieved = milestones().filter((m) => m.achievedAt && !m.celebratedAt && !beforeMs.find((b) => b.id === m.id)?.achievedAt);
    const res: BatchResponse = { created, before, after, newlyAchieved };
    return wait(res);
  },

  listConsultations: async () => wait([...db.consultations].sort((a, b) => b.date.localeCompare(a.date)), 150),
  saveConsultation: async (c) => {
    if (c.number < 1 || c.number > 999) return fail('Ce nombre de consultations est improbable.');
    return wait(upsert(db.consultations, c));
  },
  listGardes: async () => wait([...db.gardes].sort((a, b) => b.dateOfStart.localeCompare(a.dateOfStart)), 150),
  saveGarde: async (g) => {
    if (g.dateOfEnd <= g.dateOfStart) return fail('La fin doit être après le début.');
    return wait(upsert(db.gardes, g));
  },
  listFormations: async () => wait([...db.formations].sort((a, b) => b.dateOfStart.localeCompare(a.dateOfStart)), 150),
  saveFormation: async (f) => {
    if (f.dateOfEnd < f.dateOfStart) return fail('La fin doit être après le début.');
    return wait(upsert(db.formations, f));
  },
};
