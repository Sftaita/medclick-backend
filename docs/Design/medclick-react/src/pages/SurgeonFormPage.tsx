import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Switch, TopBar } from '@/components/ui';
import { surgeonName } from '@/components/CommonFieldsForm';
import { YEAR_LABEL } from '@/data/referentiel';
import type { Surgeon } from '@/types';

type Draft = Omit<Surgeon, 'id'> & { id?: string };

/**
 * Ajouter / modifier un chirurgien (`POST`/`PUT /api/surgeons`). Un seul maître de stage par année.
 * Supprimer un chirurgien supprime aussi ses interventions (règle du backend) : confirmation explicite.
 */
export default function SurgeonFormPage() {
  const { id } = useParams();
  const isNew = !id || id === 'nouveau';
  const [params] = useSearchParams();
  const nav = useNavigate();
  const [s, setS] = useState<Draft>({ firstName: '', lastName: '', yearId: params.get('annee') ?? '', boss: false });
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { data: years } = useAsync(() => api.listYears(), []);
  const { data: others } = useAsync(() => (s.yearId ? api.listSurgeons(s.yearId) : Promise.resolve([])), [s.yearId]);
  const { data: surgeries } = useAsync(() => (isNew ? Promise.resolve([]) : api.listSurgeries()), [isNew]);
  const set = (p: Partial<Draft>) => setS((x) => ({ ...x, ...p }));
  const currentBoss = others?.find((o) => o.boss && o.id !== s.id);
  const linked = surgeries?.filter((x) => x.supervisorId === id).length ?? 0;

  useEffect(() => {
    if (!isNew && s.yearId) api.listSurgeons(s.yearId).then((l) => { const f = l.find((x) => x.id === id); if (f) setS(f); }).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, isNew]);

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true);
    setError(null);
    try { await fn(); nav('/chirurgiens', { replace: true }); } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };

  return (
    <div className="screen">
      <TopBar title={isNew ? 'Ajouter un chirurgien' : 'Modifier le chirurgien'} />
      <form className="screen-body narrow" style={{ gap: 16 }} onSubmit={(e) => { e.preventDefault(); run(() => api.saveSurgeon(s)); }}>
        <div className="grid-2" style={{ gap: 10 }}>
          <label className="fl"><span>Prénom</span><input className="input" required value={s.firstName} onChange={(e) => set({ firstName: e.target.value })} /></label>
          <label className="fl"><span>Nom</span><input className="input" required value={s.lastName} onChange={(e) => set({ lastName: e.target.value })} /></label>
        </div>
        <label className="fl"><span>Année</span>
          <select className="select" value={s.yearId} onChange={(e) => set({ yearId: e.target.value })}>
            {(years ?? []).map((y) => <option key={y.id} value={y.id}>{YEAR_LABEL(y.yearOfFormation)} · {y.hospital}</option>)}
          </select>
        </label>
        <label className="card card--outline row" style={{ flexDirection: 'row', gap: 12, cursor: 'pointer' }}>
          <span className="grow stack" style={{ gap: 3 }}>
            <strong style={{ fontSize: 15 }}>Maître de stage</strong>
            <span className="muted small" style={{ lineHeight: 1.4 }}>
              {s.boss && currentBoss ? `Un seul par année : ${surgeonName(currentBoss)} perdra ce statut.` : 'Un seul par année. Il apparaît en premier dans le carnet.'}
            </span>
          </span>
          <Switch checked={s.boss} onChange={(boss) => set({ boss })} label="Maître de stage" />
        </label>
        {!isNew && (confirm ? (
          <div className="card card--outline" style={{ gap: 10, borderColor: '#F3C9C9', background: '#FFFAFA' }}>
            <span style={{ fontSize: 14, lineHeight: 1.45 }}><strong>Supprimer ce chirurgien ?</strong> {linked > 0 ? `Les ${linked} interventions où il apparaît seront aussi supprimées.` : 'Aucune intervention ne lui est liée.'}</span>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn btn--secondary btn--sm" onClick={() => setConfirm(false)}>Annuler</button>
              <button type="button" className="btn btn--danger" disabled={busy} onClick={() => run(() => api.deleteSurgeon(id!))}>Supprimer</button>
            </div>
          </div>
        ) : (
          <button type="button" className="btn btn--danger-ghost" onClick={() => setConfirm(true)}>Supprimer ce chirurgien</button>
        ))}
        {error && <p className="banner banner--danger" role="alert">{error}</p>}
        <button type="submit" hidden />
      </form>
      <div className="bottom-action">
        <button type="button" className="btn btn--primary" disabled={busy || !s.firstName.trim() || !s.lastName.trim() || !s.yearId} onClick={() => run(() => api.saveSurgeon(s))}>
          {busy ? <><span className="spinner" />Enregistrement…</> : 'Enregistrer'}
        </button>
      </div>
    </div>
  );
}
