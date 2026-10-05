import type { MedClickApi } from './types';

const BASE = import.meta.env.VITE_API_URL ?? '/api';

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message);
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(BASE + path, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(init.headers ?? {}) },
    ...init,
  });
  if (!res.ok) {
    let msg = res.statusText;
    try { msg = (await res.json()).message ?? msg; } catch { /* corps vide */ }
    throw new ApiError(res.status, msg);
  }
  return res.status === 204 ? (undefined as T) : res.json();
}

const json = (body: unknown) => JSON.stringify(body);

/** Implémentation réelle. Les routes sont décrites dans docs/API.md. */
export const httpApi: MedClickApi = {
  login: (email, password) => request('/auth/login', { method: 'POST', body: json({ email, password }) }),
  getDashboard: () => request('/dashboard'),

  listSurgeries: (f = {}) => {
    const p = new URLSearchParams();
    if (f.region) p.set('region', f.region);
    if (f.q) p.set('q', f.q);
    return request(`/surgeries${p.toString() ? '?' + p : ''}`);
  },
  getSurgery: (id) => request(`/surgeries/${id}`),
  updateSurgery: (id, patch) => request(`/surgeries/${id}`, { method: 'PATCH', body: json(patch) }),
  deleteSurgery: (id) => request(`/surgeries/${id}`, { method: 'DELETE' }),
  createBatch: (req) => request('/surgeries/batch', { method: 'POST', body: json(req) }),

  getFavoriteActeIds: () => request('/me/favorite-actes'),
  setFavoriteActe: (acteId, favorite) =>
    request(`/me/favorite-actes/${acteId}`, { method: favorite ? 'PUT' : 'DELETE' }),

  getWeek: (offset = 0) => request(`/weeks/current?offset=${offset}`),
  getDay: (date) => request(`/days/${date}`),

  getMilestones: () => request('/milestones'),
  markMilestonesCelebrated: (ids) => request('/milestones/celebrated', { method: 'POST', body: json({ ids }) }),
  acknowledgeStreak: (weeks) => request('/me/streak/ack', { method: 'POST', body: json({ weeks }) }),

  getStatistics: () => request('/statistics'),
  getProfile: () => request('/me'),
  getPartner: () => request('/partners/current'),
};
