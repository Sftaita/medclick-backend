import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, Skeleton, TopBar } from '@/components/ui';
import { FavoriteStar, Reveal } from '@/motion';
import { actesById, SURGEONS, TRAINING_YEARS } from '@/data/actes';
import { yearLabel } from '@/lib/format';
import { ROLE_LABEL, ROLES, type Surgery } from '@/types';

export default function InterventionDetailPage() {
  const { id = '' } = useParams();
  const nav = useNavigate();
  const { data, error, reload } = useAsync(() => api.getSurgery(id), [id]);
  const [draft, setDraft] = useState<Surgery | null>(null);
  const [fav, setFav] = useState(false);
  const [state, setState] = useState<'idle' | 'saving' | 'saved'>('idle');
  const [confirmDelete, setConfirmDelete] = useState(false);

  useEffect(() => { if (data) setDraft(data); }, [data]);
  useEffect(() => {
    if (data) api.getFavoriteActeIds().then((ids) => setFav(ids.includes(data.acteId))).catch(() => undefined);
  }, [data]);

  if (error) return <div className="screen"><TopBar title="Détails de l'intervention" /><div className="screen-body"><ErrorState error={error} onRetry={reload} /></div></div>;
  if (!draft) return <div className="screen"><TopBar title="Détails de l'intervention" /><div className="screen-body"><Skeleton h={86} r={18} /><Skeleton h={220} r={18} /></div></div>;

  const acte = actesById[draft.acteId];
  const set = (p: Partial<Surgery>) => { setDraft({ ...draft, ...p }); setState('idle'); };

  const save = async () => {
    setState('saving');
    const { id: _id, createdAt: _c, ...patch } = draft;
    await api.updateSurgery(id, patch);
    setState('saved'); // §37.7 : ✓ bref, sans attente artificielle
    setTimeout(() => setState('idle'), 1600);
  };
  const remove = async () => {
    await api.deleteSurgery(id);
    nav('/interventions', { replace: true, state: { message: 'Intervention supprimée' } });
  };
  const toggleFav = () => {
    const next = !fav;
    setFav(next);
    api.setFavoriteActe(draft.acteId, next).catch(() => setFav(!next));
  };

  return (
    <div className="screen">
      <TopBar title="Détails de l'intervention" />
      <div className="screen-body narrow">
        <div className="card row" style={{ gap: 14, padding: 16 }}>
          <span className="icon-chip icon-chip--lg"><Icon name={acte.icon} size={30} stroke={1.8} /></span>
          <span className="grow stack" style={{ gap: 2 }}>
            <strong style={{ fontSize: 20 }}>{acte.label}</strong>
            <span className="muted" style={{ fontSize: 13 }}>{acte.fullName}</span>
          </span>
          <FavoriteStar active={fav} onToggle={toggleFav} label={acte.label} />
        </div>

        <div className="r-2">
          <label className="row"><span className="field-label">Date</span>
            <input className="input" type="date" value={draft.date} onChange={(e) => set({ date: e.target.value })} /></label>
          <label className="row"><span className="field-label">Rôle</span>
            <select className="select" value={draft.role} onChange={(e) => set({ role: e.target.value as Surgery['role'] })}>
              {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
            </select></label>
          <label className="row"><span className="field-label">Chirurgien senior</span>
            <select className="select" value={draft.surgeon} onChange={(e) => set({ surgeon: e.target.value })}>
              {[...new Set([draft.surgeon, ...SURGEONS])].map((s) => <option key={s}>{s}</option>)}
            </select></label>
          <label className="row"><span className="field-label">Année de formation</span>
            <select className="select" value={draft.trainingYear} onChange={(e) => set({ trainingYear: Number(e.target.value) })}>
              {TRAINING_YEARS.map((y) => <option key={y} value={y}>{yearLabel(y)}</option>)}
            </select></label>
        </div>

        <label className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 13 }}>Nomenclature (optionnel)</span>
          <input className="input" value={draft.nomenclature ?? ''} placeholder="Rechercher un code" onChange={(e) => set({ nomenclature: e.target.value || undefined })} />
        </label>
        <label className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 13 }}>Notes personnelles</span>
          <textarea className="textarea" rows={4} maxLength={500} value={draft.notes ?? ''} onChange={(e) => set({ notes: e.target.value })} />
          <span className="muted small" style={{ alignSelf: 'flex-end' }}>{(draft.notes ?? '').length}/500</span>
        </label>

        <button type="button" className="btn btn--primary pressable" onClick={save} disabled={state === 'saving'}
          style={state === 'saved' ? { background: 'var(--mc-success)', boxShadow: 'none' } : undefined}>
          {state === 'saving' ? <><span className="spinner" />Enregistrement…</>
            : state === 'saved' ? <span className="row mc-pop"><Icon name="check" size={18} stroke={3} />Intervention enregistrée</span>
            : <>Enregistrer l'intervention<Icon name="arrowR" size={18} stroke={2.4} /></>}
        </button>

        {/* §37.19 — suppression neutre, confirmation d'abord */}
        {confirmDelete ? (
          <Reveal className="card card--outline" style={{ gap: 10 }}>
            <span style={{ fontSize: 14 }}>Supprimer cette intervention ? Les autres interventions du même lot ne sont pas touchées.</span>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button type="button" className="btn btn--secondary btn--sm" onClick={() => setConfirmDelete(false)}>Annuler</button>
              <button type="button" className="btn btn--danger" onClick={remove}>Supprimer</button>
            </div>
          </Reveal>
        ) : (
          <button type="button" className="btn btn--danger-ghost" onClick={() => setConfirmDelete(true)}>
            <Icon name="trash" size={18} />Supprimer cette intervention
          </button>
        )}
      </div>
    </div>
  );
}
