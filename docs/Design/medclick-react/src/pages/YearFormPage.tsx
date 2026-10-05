import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '@/api';
import { Icon } from '@/components/Icon';
import { TopBar } from '@/components/ui';
import { InfoCard } from '@/components/StatusHero';
import { YEAR_LONG } from '@/data/referentiel';
import type { TrainingYear } from '@/types';

type Draft = Omit<TrainingYear, 'id'> & { id?: string };

/** Créer (`POST /api/years/create`, une fiche par année) ou modifier (`PUT /api/years/{id}`) une année de formation. */
export default function YearFormPage() {
  const { id } = useParams();
  const isNew = !id || id === 'nouvelle';
  const nav = useNavigate();
  const [y, setY] = useState<Draft>({ yearOfFormation: 1, dateOfStart: `${new Date().getFullYear()}-09-01`, hospital: '', master: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = (p: Partial<Draft>) => setY((x) => ({ ...x, ...p }));

  useEffect(() => {
    api.listYears().then((list) => {
      if (!isNew) { const f = list.find((x) => x.id === id); if (f) setY(f); return; }
      const last = [...list].sort((a, b) => b.yearOfFormation - a.yearOfFormation)[0];
      if (last) setY((x) => ({ ...x, yearOfFormation: Math.min(8, last.yearOfFormation + 1), hospital: last.hospital, master: last.master }));
    }).catch(() => undefined);
  }, [id, isNew]);

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.saveYear(y);
      nav(isNew ? '/chirurgiens' : '/annees', { replace: true });
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="screen">
      <TopBar title={isNew ? 'Nouvelle année' : "Modifier l'année"} />
      <form className="screen-body narrow" style={{ gap: 16 }} onSubmit={(e) => { e.preventDefault(); save(); }}>
        <label className="fl"><span>Année de formation</span>
          <select className="select" value={y.yearOfFormation} onChange={(e) => set({ yearOfFormation: Number(e.target.value) })}>
            {YEAR_LONG.slice(1).map((l, i) => <option key={l} value={i + 1}>{l} année</option>)}
          </select>
        </label>
        <label className="fl"><span>Début du stage</span><input className="input" type="date" required value={y.dateOfStart} onChange={(e) => set({ dateOfStart: e.target.value })} /></label>
        <label className="fl"><span>Hôpital de stage</span><input className="input" required maxLength={150} value={y.hospital} onChange={(e) => set({ hospital: e.target.value })} /></label>
        <label className="fl"><span>Maître de stage</span><input className="input" required value={y.master} onChange={(e) => set({ master: e.target.value })} /></label>
        {isNew && <InfoCard>Une seule fiche par année de formation. Ajoutez ensuite vos chirurgiens pour pouvoir encoder vos interventions.</InfoCard>}
        {error && <p className="banner banner--danger" role="alert">{error}</p>}
        <button type="submit" hidden />
      </form>
      <div className="bottom-action">
        <button type="button" className="btn btn--primary" disabled={busy || !y.hospital.trim() || !y.master.trim()} onClick={save}>
          {busy ? <><span className="spinner" />Enregistrement…</> : <>{isNew ? "Créer l'année" : 'Enregistrer'}<Icon name="arrowR" size={18} stroke={2.4} /></>}
        </button>
      </div>
    </div>
  );
}
