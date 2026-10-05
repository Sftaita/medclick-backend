// Implémentation réelle sur le backend MedClick (Symfony + API Platform).
// Routes existantes : mêmes appels que le front actuel (medclick-pwa). Routes absentes : `notYet()`
// renvoie une erreur explicite. La liste complète et les payloads sont dans docs/API.md.
import type { MedClickApi } from './types';
import type {
  Acte, BatchResponse, Consultation, Favorite, Formation, Garde, Region, Role, Surgeon, Surgery, TrainingYear, UserProfile,
} from '@/types';
import { ROLE_POSITION } from '@/types';

const BASE = (import.meta.env.VITE_API_URL ?? '/api').replace(/\/$/, '');
const TOKEN_KEY = 'mc.token';

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message);
  }
}

const readToken = () => { try { return localStorage.getItem(TOKEN_KEY); } catch { return null; } };
const writeToken = (t: string | null) => { try { if (t) localStorage.setItem(TOKEN_KEY, t); else localStorage.removeItem(TOKEN_KEY); } catch { /* stockage indisponible */ } };

async function raw(path: string, init: RequestInit = {}): Promise<Response> {
  const token = readToken();
  const res = await fetch(BASE + path, {
    ...init,
    headers: {
      Accept: 'application/ld+json, application/json',
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(init.headers ?? {}),
    },
  });
  if (!res.ok) {
    let msg = res.statusText;
    try {
      const b = await res.json();
      msg = b.message ?? b.error ?? b['hydra:description'] ?? b.violations?.[0]?.message ?? msg;
    } catch { /* corps vide */ }
    throw new ApiError(res.status, msg);
  }
  return res;
}
async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await raw(path, init);
  return res.status === 204 ? (undefined as T) : res.json();
}
const json = (body: unknown) => JSON.stringify(body);
const members = <T,>(r: { 'hydra:member'?: T[] }) => r['hydra:member'] ?? [];
const notYet = (route: string): Promise<never> => Promise.reject(new ApiError(501, `Route à créer côté backend : ${route} (voir docs/API.md)`));

/* --------------------------------------------------------------- Mappers */
type Hydra = { '@id'?: string; id?: number | string };
const idOf = (x: Hydra | string | null | undefined) =>
  x == null ? '' : typeof x === 'string' ? x.split('/').pop() ?? '' : String(x.id ?? x['@id']?.split('/').pop() ?? '');
const yearIri = (id: string) => `/api/years/${id}`;
const isoDate = (s: string) => s.slice(0, 10);
const isoDateTime = (s: string) => s.slice(0, 16);
const POSITION_ROLE: Record<number, Role> = { 1: 'SOLO', 2: 'SECOND', 3: 'ASSISTED' };

/* eslint-disable @typescript-eslint/no-explicit-any */
const toYear = (y: any): TrainingYear => ({
  id: idOf(y), yearOfFormation: Number(y.yearOfFormation), dateOfStart: isoDate(y.dateOfStart ?? ''), hospital: y.hospital ?? '', master: y.master ?? '',
});
const toSurgeon = (s: any, yearId: string): Surgeon => ({
  id: idOf(s), firstName: s.firstName ?? '', lastName: s.lastName ?? '', yearId, boss: !!s.boss,
});
const toActe = (n: any): Acte => ({
  id: idOf(n), name: n.name, speciality: n.speciality,
  orthoType: n.type === '2' || n.type === 2 ? 'trauma' : n.speciality === 'ortho' ? 'elective' : undefined,
  region: (n.subType || undefined) as Region | undefined,
  code: n.codeHospitalisation ? `${n.codeHospitalisation}${n.n ?? ''}` : undefined,
});
const toSurgery = (s: any): Surgery => {
  const role = POSITION_ROLE[Number(s.position)] ?? 'SOLO';
  return {
    id: idOf(s), date: isoDate(s.date ?? ''), role,
    acte: { id: idOf(s.nomenclature), name: s.name ?? '', speciality: s.speciality, code: s.code ?? undefined },
    supervisorId: role === 'SECOND' ? s.firstHand ?? undefined : role === 'ASSISTED' ? s.secondHand ?? undefined : undefined,
    yearId: idOf(s.year), createdAt: s.createdAt ?? '',
  };
};
const toConsultation = (c: any): Consultation => ({
  id: idOf(c), date: isoDate(c.date), dayPart: c.dayPart ?? 'morning', speciality: c.speciality, number: Number(c.number), yearId: idOf(c.year),
});
const toGarde = (g: any): Garde => ({
  id: idOf(g), dateOfStart: isoDateTime(g.dateOfStart), dateOfEnd: isoDateTime(g.dateOfEnd), number: Number(g.number), yearId: idOf(g.year),
});
const toFormation = (f: any): Formation => ({
  id: idOf(f), event: f.event, name: f.name ?? '', description: f.description ?? undefined,
  dateOfStart: isoDateTime(f.dateOfStart), dateOfEnd: isoDateTime(f.dateOfEnd),
  local: f.location === 'local', location: f.location === 'local' ? undefined : f.location ?? undefined,
  role: f.role ?? 'participant', yearId: idOf(f.year),
});
/** Payload attendu par le contrôleur des interventions (`firstHand`/`secondHand` selon le rôle). */
const surgeryPayload = (s: Omit<Surgery, 'id' | 'createdAt'>) => ({
  year: Number(s.yearId), surgeryId: Number(s.acte.id), date: s.date, position: ROLE_POSITION[s.role],
  firstHand: s.role === 'SECOND' ? Number(s.supervisorId) : null,
  secondHand: s.role === 'ASSISTED' ? Number(s.supervisorId) : null,
});

/** Décode les claims ajoutés au JWT (`JwtCreatedSubscriber`). */
function claims(token: string): any {
  try { return JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'))); } catch { return {}; }
}

async function currentProfile(): Promise<UserProfile> {
  const token = readToken();
  const c = token ? claims(token) : {};
  const [users, years] = await Promise.all([
    request<{ 'hydra:member'?: any[] }>('/users').catch(() => ({ 'hydra:member': [] })),
    httpApi.listYears().catch(() => [] as TrainingYear[]),
  ]);
  const u = members(users)[0] ?? {};
  const currentYear = [...years].sort((a, b) => b.yearOfFormation - a.yearOfFormation)[0] ?? null;
  return {
    firstName: c.firstname ?? u.firstname ?? '', lastName: c.lastname ?? u.lastname ?? '', email: c.email ?? u.email ?? '',
    speciality: u.speciality ?? 'ortho', currentYear, termsAccepted: !!(c.acceptedTerms ?? u.acceptedTerms),
  };
}

function pickFormat(f: Record<string, string | null>) {
  const w = window.innerWidth;
  return (w <= 600 ? f.smartphone : w <= 900 ? f.tablet_portrait : w <= 1200 ? f.tablet_landscape : w <= 1600 ? f.screen_14_inch : f.large_screen) ?? undefined;
}

async function download(path: string, filename: string) {
  const res = await raw(path);
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

/* ------------------------------------------------------------------- API */
export const httpApi: MedClickApi = {
  login: async (email, password) => {
    const { token } = await request<{ token: string }>('/login_check', { method: 'POST', body: json({ username: email, password }) });
    writeToken(token);
    return currentProfile();
  },
  logout: async () => writeToken(null),
  register: (r) => request('/users', { method: 'POST', body: json({ email: r.email, password: r.password, firstname: r.firstName, lastname: r.lastName, speciality: r.speciality }) }),
  forgotPassword: (email) => request('/forgottenPassword', { method: 'POST', body: json({ username: email }) }),
  resetPassword: async (email, token, password) => {
    try {
      await request('/resetPassword', { method: 'POST', body: json({ email, token, password }) });
      return 'ok';
    } catch (e) {
      if (e instanceof ApiError && [400, 403, 404, 410].includes(e.status)) return 'expired';
      throw e;
    }
  },
  getProfile: () => currentProfile(),
  getTerms: async () => {
    const t = await request<any>('/terms-conditions');
    return { content: t.content ?? '', publishedAt: isoDate(t.publishedAt ?? '') };
  },
  acceptTerms: () => request('/acceptTerms', { method: 'PUT' }),

  // Le partenaire n'existe pas encore côté backend : sans route, aucun emplacement sponsor n'est affiché.
  getPartner: () => request<any>('/partner/active').catch(() => null),
  savePartner: () => notYet('PUT /api/admin/partner'),
  getCampaign: async () => {
    try {
      const c = await request<any>('/marketing/active');
      return { id: String(c.id), imageUrl: pickFormat(c.formats ?? {}), redirectUrl: c.redirect_url ?? undefined, durationMs: (Number(c.duration) || 5) * 1000 };
    } catch {
      return null; // 404 = aucune campagne active
    }
  },
  registerCampaignClick: (id) => request(`/marketing/incrementCampaign/${id}`, { method: 'PUT' }),

  getDashboard: () => notYet('GET /api/dashboard'),
  getWeek: () => notYet('GET /api/weeks/current'),
  getDay: (date) => notYet(`GET /api/days/${date}`),
  getMilestones: () => notYet('GET /api/milestones'),
  markMilestonesCelebrated: () => notYet('POST /api/milestones/celebrated'),
  acknowledgeStreak: () => notYet('POST /api/me/streak/ack'),
  getStatistics: () => notYet('GET /api/statistics/me'),

  listYears: async () => members(await request<any>('/years')).map(toYear),
  saveYear: async (y) => {
    const body = json({ yearOfFormation: String(y.yearOfFormation), dateOfStart: y.dateOfStart, hospital: y.hospital, master: y.master });
    if (y.id) return toYear(await request(`/years/${y.id}`, { method: 'PUT', body }));
    await request('/years/create', { method: 'POST', body });
    const all = await httpApi.listYears();
    return all.find((x) => x.yearOfFormation === y.yearOfFormation) ?? all[0];
  },
  exportLogbook: (yearId) => download(`/excel2/${yearId}`, `carnet-de-stage-${yearId}.xlsx`),
  listSurgeons: async (yearId) => (await request<any[]>(`/list/${yearId}`)).map((s) => toSurgeon(s, yearId)),
  saveSurgeon: async (s) => {
    const body = json({ firstName: s.firstName, lastName: s.lastName, boss: s.boss, year: yearIri(s.yearId) });
    const r = await request(s.id ? `/surgeons/${s.id}` : '/surgeons', { method: s.id ? 'PUT' : 'POST', body });
    return toSurgeon(r, s.yearId);
  },
  deleteSurgeon: (id) => request(`/surgeons/${id}`, { method: 'DELETE' }),

  searchNomenclature: async (f) => {
    const q = f.q?.trim().toLowerCase();
    const list = (await request<any[]>(`/nomenclature/${f.speciality}`)).map(toActe);
    return list.filter((a) => (!f.orthoType || a.orthoType === f.orthoType) && (!f.region || a.region === f.region)
      && (!q || a.name.toLowerCase().includes(q) || a.code?.toLowerCase().includes(q)));
  },
  getActe: () => notYet('GET /api/nomenclature/item/{id}'),
  listFavorites: async () => (await request<any[]>('/favorites/getMyList')).map((f): Favorite => ({
    id: String(f.id), shortcut: f.shorcut ?? f.shortcut ?? f.name,
    acte: { id: String(f.surgeryId), name: f.name, speciality: f.speciality, code: f.codeHospitalisation ?? undefined },
  })),
  addFavorite: async (acteId, shortcut) => {
    await request('/favorites/addNew', { method: 'POST', body: json({ surgeryId: Number(acteId), shortcut }) });
    const list = await httpApi.listFavorites();
    return list.find((f) => f.acte.id === acteId) ?? list[0];
  },
  renameFavorite: async (id, shortcut) => {
    const fav = (await httpApi.listFavorites()).find((f) => f.id === id);
    await request('/favorites/updateNew', { method: 'PUT', body: json({ favoriteId: Number(id), surgeryId: Number(fav?.acte.id), shortcut }) });
  },
  removeFavorite: (id) => request(`/favorites/${id}`, { method: 'DELETE' }),

  listSurgeries: async (f = {}) => {
    const q = f.q?.toLowerCase();
    return members(await request<any>('/surgeries')).map(toSurgery)
      .filter((s) => (!f.speciality || s.acte.speciality === f.speciality) && (!q || s.acte.name.toLowerCase().includes(q)));
  },
  getSurgery: async (id) => toSurgery(await request(`/surgeries/${id}`)),
  updateSurgery: async (id, patch) => {
    const current = await httpApi.getSurgery(id);
    return toSurgery(await request(`/surgeries/${id}`, { method: 'PUT', body: json(surgeryPayload({ ...current, ...patch })) }));
  },
  deleteSurgery: (id) => request(`/surgeries/${id}`, { method: 'DELETE' }),
  // Pas de création non atomique en boucle : on attend la route transactionnelle.
  createBatch: (req) => request<BatchResponse>('/surgeries/batch', { method: 'POST', body: json(req) }).catch((e) =>
    e instanceof ApiError && e.status === 404 ? notYet('POST /api/surgeries/batch') : Promise.reject(e)),

  listConsultations: async () => members(await request<any>('/consultations')).map(toConsultation),
  saveConsultation: async (c) => {
    const body = json({ date: c.date, number: String(c.number), dayPart: c.dayPart, speciality: c.speciality, year: yearIri(c.yearId) });
    return toConsultation(await request(c.id ? `/consultations/${c.id}` : '/consultations', { method: c.id ? 'PUT' : 'POST', body }));
  },
  listGardes: async () => members(await request<any>('/gardes')).map(toGarde),
  saveGarde: async (g) => {
    const body = json({ dateOfStart: g.dateOfStart, dateOfEnd: g.dateOfEnd, number: String(g.number), year: yearIri(g.yearId) });
    return toGarde(await request(g.id ? `/gardes/${g.id}` : '/gardes', { method: g.id ? 'PUT' : 'POST', body }));
  },
  listFormations: async () => members(await request<any>('/formations')).map(toFormation),
  saveFormation: async (f) => {
    const body = json({
      event: f.event, name: f.name, description: f.description ?? null, dateOfStart: f.dateOfStart, dateOfEnd: f.dateOfEnd,
      location: f.local ? 'local' : f.location ?? null, role: f.role, year: yearIri(f.yearId),
    });
    return toFormation(await request(f.id ? `/formations/${f.id}` : '/formations', { method: f.id ? 'PUT' : 'POST', body }));
  },
};
