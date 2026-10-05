import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { TopBar } from '@/components/ui';
import { YearSelect } from '@/components/YearSelect';
import { todayISO } from '@/lib/format';
import { FORMATION_EVENT_LABEL, FORMATION_ROLE_LABEL, type Formation, type FormationEvent, type FormationRole } from '@/types';

type Draft = Omit<Formation, 'id'> & { id?: string };

/** Ajouter ou modifier une formation (`POST`/`PUT /api/formations`). La description est reprise dans le carnet pour un orateur ou un organisateur. */
export default function FormationFormPage() {
  const { id } = useParams();
  const nav = useNavigate();
  const { data: profile } = useAsync(() => api.getProfile(), []);
  const t = todayISO();
  const [f, setF] = useState<Draft>({ event: 'staff', name: '', dateOfStart: `${t}T08:00`, dateOfEnd: `${t}T09:00`, local: true, role: 'participant', yearId: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = (p: Partial<Draft>) => setF((x) => ({ ...x, ...p }));
  const part = (dt: string) => [dt.slice(0, 10), dt.slice(11, 16)] as const;
  const [sd, st] = part(f.dateOfStart);
  const [ed, et] = part(f.dateOfEnd);

  useEffect(() => {
    if (id) api.listFormations().then((l) => { const x = l.find((y) => y.id === id); if (x) setF(x); }).catch(() => undefined);
  }, [id]);

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.saveFormation(f);
      nav('/activites/formations', { replace: true });
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="screen">
      <TopBar title={id ? 'Modifier la formation' : 'Ajouter une formation'} />
      <div className="screen-body narrow">
        <section className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 14 }}>Type d'évènement</span>
          <div className="segmented" role="group" aria-label="Type d'évènement">
            {(Object.keys(FORMATION_EVENT_LABEL) as FormationEvent[]).map((e) => (
              <button key={e} type="button" aria-pressed={f.event === e} onClick={() => set({ event: e })}>{FORMATION_EVENT_LABEL[e]}</button>
            ))}
          </div>
        </section>

        <label className="fl"><span>Sujet</span><input className="input" required maxLength={255} value={f.name} onChange={(e) => set({ name: e.target.value })} /></label>

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
        </section>

        <section className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 14 }}>À l'hôpital de stage ?</span>
          <div className="segmented segmented--sm" role="group" aria-label="Lieu">
            <button type="button" aria-pressed={f.local} onClick={() => set({ local: true })}>{profile?.currentYear?.hospital ?? 'Hôpital de stage'}</button>
            <button type="button" aria-pressed={!f.local} onClick={() => set({ local: false })}>Ailleurs</button>
          </div>
          {!f.local && <input className="input" aria-label="Lieu de l'évènement" placeholder="Ville ou établissement" value={f.location ?? ''} onChange={(e) => set({ location: e.target.value })} />}
        </section>

        <section className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 14 }}>Mon rôle</span>
          <div className="pill-group" role="group" aria-label="Mon rôle">
            {(Object.keys(FORMATION_ROLE_LABEL) as FormationRole[]).map((r) => (
              <button key={r} type="button" className="pill" aria-pressed={f.role === r} onClick={() => set({ role: r })}>{FORMATION_ROLE_LABEL[r]}</button>
            ))}
          </div>
        </section>

        <label className="fl"><span>Description</span>
          <textarea className="textarea" rows={3} value={f.description ?? ''} onChange={(e) => set({ description: e.target.value || undefined })} />
          <span className="muted small">Reprise dans le carnet à côté du rôle (orateur ou organisateur).</span>
        </label>

        <YearSelect value={f.yearId} onChange={(yearId) => set({ yearId })} />
        {error && <p className="banner banner--danger" role="alert">{error}</p>}
      </div>
      <div className="bottom-action">
        <button type="button" className="btn btn--primary" disabled={busy || !f.name.trim() || !f.yearId} onClick={save}>
          {busy ? <><span className="spinner" />Enregistrement…</> : <>Enregistrer la formation<Icon name="arrowR" size={18} stroke={2.4} /></>}
        </button>
        <span className="muted small center">Reprise dans l'export Excel, onglet Formations</span>
      </div>
    </div>
  );
}
