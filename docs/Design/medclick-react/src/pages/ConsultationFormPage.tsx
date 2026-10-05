import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/api';
import { Icon } from '@/components/Icon';
import { TopBar } from '@/components/ui';
import { DateQuick, YearSelect } from '@/components/YearSelect';
import { CountStepper } from '@/motion';
import { CONSULTATION_SPECIALITIES, CONSULTATION_SPECIALITY_SHORT } from '@/data/referentiel';
import { todayISO } from '@/lib/format';
import { DAY_PART_LABEL, type Consultation, type DayPart } from '@/types';

type Draft = Omit<Consultation, 'id'> & { id?: string };

/** Ajouter ou modifier une consultation (`POST`/`PUT /api/consultations`). `?date=&moment=` pré-remplit depuis la vue du jour. */
export default function ConsultationFormPage() {
  const { id } = useParams();
  const [params] = useSearchParams();
  const nav = useNavigate();
  const [c, setC] = useState<Draft>({
    date: params.get('date') ?? todayISO(), dayPart: (params.get('moment') as DayPart) ?? 'morning',
    speciality: 'ortho', number: 10, yearId: '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = (p: Partial<Draft>) => setC((x) => ({ ...x, ...p }));

  useEffect(() => {
    if (id) api.listConsultations().then((l) => { const f = l.find((x) => x.id === id); if (f) setC(f); }).catch(() => undefined);
  }, [id]);

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.saveConsultation(c);
      nav('/activites/consultations', { replace: true });
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="screen">
      <TopBar title={id ? 'Modifier la consultation' : 'Ajouter une consultation'} />
      <div className="screen-body narrow">
        <section className="card card--tint">
          <div className="row" style={{ justifyContent: 'space-between' }}>
            <span className="stack" style={{ gap: 2 }}><span className="eyebrow">Patients vus</span><span style={{ fontSize: 17, fontWeight: 700 }}>Consultation</span></span>
            <CountStepper value={c.number} onChange={(n) => set({ number: n })} min={1} max={999} label="Nombre de patients" />
          </div>
          <span className="muted" style={{ fontSize: 13 }}>Patients vus pendant la demi-journée choisie ci-dessous.</span>
        </section>

        <section className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 14 }}>Moment de la journée</span>
          <div className="pill-group" role="group" aria-label="Moment de la journée">
            {(Object.keys(DAY_PART_LABEL) as DayPart[]).map((p) => (
              <button key={p} type="button" className="pill" aria-pressed={c.dayPart === p} onClick={() => set({ dayPart: p })}>{DAY_PART_LABEL[p]}</button>
            ))}
          </div>
        </section>

        <section className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 14 }}>Spécialité</span>
          <div className="chips" role="group" aria-label="Spécialité">
            {CONSULTATION_SPECIALITIES.map((s) => (
              <button key={s} type="button" className="chip" aria-pressed={c.speciality === s} onClick={() => set({ speciality: s })}>{CONSULTATION_SPECIALITY_SHORT[s]}</button>
            ))}
          </div>
        </section>

        <section className="list">
          <DateQuick value={c.date} today={todayISO()} onChange={(date) => set({ date })} />
          <YearSelect value={c.yearId} onChange={(yearId) => set({ yearId })} />
        </section>
        {error && <p className="banner banner--danger" role="alert">{error}</p>}
      </div>
      <div className="bottom-action">
        <button type="button" className="btn btn--primary" disabled={busy || !c.yearId} onClick={save}>
          {busy ? <><span className="spinner" />Enregistrement…</> : <>Enregistrer la consultation<Icon name="arrowR" size={18} stroke={2.4} /></>}
        </button>
        <span className="muted small center">Reprise dans l'export Excel, onglet Consultations</span>
      </div>
    </div>
  );
}
