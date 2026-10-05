const DAY_MS = 86_400_000;

/** Date locale (et non UTC) au format AAAA-MM-JJ. */
export const localISO = (d: Date) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
export const todayISO = () => localISO(new Date());
export const addDaysISO = (iso: string, n: number) =>
  localISO(new Date(new Date(iso + 'T12:00:00').getTime() + n * DAY_MS));

export function relativeDayLabel(iso: string): string {
  const t = todayISO();
  if (iso === t) return "Aujourd'hui";
  if (iso === addDaysISO(t, -1)) return 'Hier';
  if (iso === addDaysISO(t, -2)) return 'Avant-hier';
  return new Date(iso + 'T12:00:00').toLocaleDateString('fr-BE', { day: 'numeric', month: 'short', year: 'numeric' });
}


export const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`;

export const formatDelta = (d: number | null) => (d === null ? null : `${d > 0 ? '+' : ''}${d} %`);

export const percent = (v: number, total: number) => (total <= 0 ? 0 : Math.round((v / total) * 100));

const asDate = (iso: string) => new Date(iso.length > 10 ? iso : iso + 'T12:00:00');
/** « mar. 29 sept. » */
export const shortDay = (iso: string) => asDate(iso).toLocaleDateString('fr-BE', { weekday: 'short', day: 'numeric', month: 'short' });
/** « Mardi 29 sept. » */
export const dayLabel = (iso: string) => {
  const s = asDate(iso).toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'short' });
  return s.charAt(0).toUpperCase() + s.slice(1);
};
/** « Septembre 2026 » */
export const monthLabel = (iso: string) => {
  const s = asDate(iso.slice(0, 10)).toLocaleDateString('fr-BE', { month: 'long', year: 'numeric' });
  return s.charAt(0).toUpperCase() + s.slice(1);
};
/** « 20 h 00 » à partir de AAAA-MM-JJTHH:MM */
export const hourLabel = (dt: string) => dt.slice(11, 16).replace(':', ' h ');
/** Durée en heures entières entre deux AAAA-MM-JJTHH:MM */
export const hoursBetween = (a: string, b: string) => Math.round((new Date(b).getTime() - new Date(a).getTime()) / 3_600_000);
/** Regroupe une liste par mois (clé AAAA-MM), du plus récent au plus ancien. */
export function groupByMonth<T>(list: T[], date: (x: T) => string): [string, T[]][] {
  const m = new Map<string, T[]>();
  list.forEach((x) => { const k = date(x).slice(0, 7); m.set(k, [...(m.get(k) ?? []), x]); });
  return [...m.entries()].sort((a, b) => b[0].localeCompare(a[0]));
}
