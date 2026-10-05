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

export const longDate = (iso: string) =>
  new Date(iso + 'T12:00:00').toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

export const yearLabel = (y: number) => (y === 1 ? '1re année' : `${y}e année`);

export const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`;

export const formatDelta = (d: number | null) => (d === null ? null : `${d > 0 ? '+' : ''}${d} %`);

export const percent = (v: number, total: number) => (total <= 0 ? 0 : Math.round((v / total) * 100));
