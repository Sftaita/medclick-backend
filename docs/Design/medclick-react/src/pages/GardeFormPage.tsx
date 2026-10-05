import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/api';
import { Icon } from '@/components/Icon';
import { TopBar } from '@/components/ui';
import { YearSelect } from '@/components/YearSelect';
import { CountStepper } from '@/motion';
import { addDaysISO, hoursBetween, shortDay, todayISO } from '@/lib/format';
import type { Garde } from '@/types';

type Draft = Omit<Garde, 'id'> & { id?: string };
const PRESETS = [
  { label: 'Nuit · 20 h → 8 h', start: '20:00', end: '08:00', nextDay: true },
  { label: 'Jour · 8 h → 20 h', start: '08:00', end: '20:00', nextDay: false },
  { label: '24 h', start: '08:00', end: '08:00', nextDay: true },
];

/** Ajouter ou modifier une garde (`POST`/`PUT /api/gardes`). */
export default function GardeFormPage() {
  const { id } = useParams();
  const [params] = useSearchParams();
  const nav = useNavigate();
  const day = params.get('date') ?? todayISO();
  const [g, setG] = useState<Draft>({ dateOfStart: `${day}T20:00`, dateOfEnd: `${addDaysISO(day, 1)}T08:00`, number: 5, yearId: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = (p: Partial<Draft>) => setG((x) => ({ ...x, ...p }));
  const split = (dt: string) => [dt.slice(0, 10), dt.slice(11, 16)] as const;
  const [sd, st] = split(g.dateOfStart);
  const [ed, et] = split(g.dateOfEnd);
  const hours = hoursBetween(g.dateOfStart, g.dateOfEnd);

  useEffect(() => {
    if (id) api.listGardes().then((l) => { const f = l.find((x) => x.id === id); if (f) setG(f); }).catch(() => undefined);
  }, [id]);

  const preset = (p: typeof PRESETS[number]) => set({ dateOfStart: `${sd}T${p.start}`, dateOfEnd: `${p.nextDay ? addDaysISO(sd, 1) : sd}T${p.end}` });
  const isPreset = (p: typeof PRESETS[number]) => st === p.start && et === p.end && ed === (p.nextDay ? addDaysISO(sd, 1) : sd);

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.saveGarde(g);
      nav('/activites/gardes', { replace: true });
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="screen">
      <TopBar title={id ? 'Modifier la garde' : 'Ajouter une garde'} />
      <div className="screen-body narrow">
        <section className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 14 }}>Type de garde</span>
          <div className="chips" role="group" aria-label="Préremplir les horaires">
            {PRESETS.map((p) => <button key={p.label} type="button" className="chip" aria-pressed={isPreset(p)} onClick={() => preset(p)}>{p.label}</button>)}
          </div>
        </section>

        <section className="card card--outline" style={{ gap: 14 }}>
          <label className="fl"><span>Début</span>
            <span className="dt">
              <input className="input" type="date" aria-label="Date de début" value={sd} onChange={(e) => set({ dateOfStart: `${e.target.value}T${st}` })} />
              <input className="input" type="time" aria-label="Heure de début" value={st} onChange={(e) => set({ dateOfStart: `${sd}T${e.target.value}` })} />
            </span>
          </label>
          <label className="fl"><span>Fin</span>
            <span className="dt">
              <input className="input" type="date" aria-label="Date de fin" value={ed} onChange={(e) => set({ dateOfEnd: `${e.target.value}T${et}` })} />
              <input className="input" type="time" aria-label="Heure de fin" value={et} onChange={(e) => set({ dateOfEnd: `${ed}T${e.target.value}` })} />
            </span>
          </label>
          <span className="row" style={{ gap: 8, fontSize: 13, fontWeight: 700, color: hours > 0 ? 'var(--mc-success)' : 'var(--mc-danger)' }}>
            <Icon name="clock" size={16} />
            {hours > 0 ? `Durée : ${hours} h · du ${shortDay(sd)} au ${shortDay(ed)}` : 'La fin doit être après le début'}
          </span>
        </section>

        <section className="card card--tint">
          <div className="row" style={{ justifyContent: 'space-between' }}>
            <span className="stack" style={{ gap: 2 }}><span className="eyebrow">Pendant la garde</span><span style={{ fontSize: 17, fontWeight: 700 }}>Patients vus</span></span>
            <CountStepper value={g.number} onChange={(n) => set({ number: n })} min={0} max={999} label="Nombre de patients vus pendant la garde" />
          </div>
        </section>

        <YearSelect value={g.yearId} onChange={(yearId) => set({ yearId })} />
        {error && <p className="banner banner--danger" role="alert">{error}</p>}
      </div>
      <div className="bottom-action">
        <button type="button" className="btn btn--primary" disabled={busy || hours <= 0 || !g.yearId} onClick={save}>
          {busy ? <><span className="spinner" />Enregistrement…</> : <>Enregistrer la garde<Icon name="arrowR" size={18} stroke={2.4} /></>}
        </button>
        <span className="muted small center">Reprise dans l'export Excel, onglet Gardes</span>
      </div>
    </div>
  );
}
