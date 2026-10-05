import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { CodeBadge, ErrorState, Skeleton, TopBar, acteIcon } from '@/components/ui';
import { surgeonName } from '@/components/CommonFieldsForm';
import { FavoriteStar, Reveal } from '@/motion';
import { SPECIALITY_SHORT, YEAR_LABEL } from '@/data/referentiel';
import { ROLE_LABEL, ROLES, SUPERVISOR_LABEL, type Role, type Surgery } from '@/types';

export default function InterventionDetailPage() {
  const { id = '' } = useParams();
  const nav = useNavigate();
  const { data, error, reload } = useAsync(() => api.getSurgery(id), [id]);
  const [draft, setDraft] = useState<Surgery | null>(null);
  const { data: years } = useAsync(() => api.listYears(), []);
  const { data: surgeons } = useAsync(() => (draft ? api.listSurgeons(draft.yearId) : Promise.resolve([])), [draft?.yearId]);
  const { data: favorites, reload: reloadFav } = useAsync(() => api.listFavorites(), []);
  const [state, setState] = useState<'idle' | 'saving' | 'saved'>('idle');
  const [saveError, setSaveError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);

  useEffect(() => { if (data) setDraft(data); }, [data]);

  if (error) return <div className="screen"><TopBar title="Détails de l'intervention" /><div className="screen-body"><ErrorState error={error} onRetry={reload} /></div></div>;
  if (!draft) return <div className="screen"><TopBar title="Détails de l'intervention" /><div className="screen-body"><Skeleton h={86} r={18} /><Skeleton h={220} r={18} /></div></div>;

  const acte = draft.acte;
  const fav = favorites?.find((f) => f.acte.id === acte.id);
  const supervisorLabel = SUPERVISOR_LABEL[draft.role];
  const set = (p: Partial<Surgery>) => { setDraft({ ...draft, ...p }); setState('idle'); setSaveError(null); };

  const save = async () => {
    setState('saving');
    try {
      const { id: _id, createdAt: _c, ...patch } = draft;
      await api.updateSurgery(id, patch);
      setState('saved'); // §37.7 : ✓ bref, sans attente artificielle
      setTimeout(() => setState('idle'), 1600);
    } catch (e) {
      setSaveError((e as Error).message);
      setState('idle');
    }
  };
  const remove = async () => {
    await api.deleteSurgery(id);
    nav('/activites', { replace: true, state: { message: 'Intervention supprimée' } });
  };
  const toggleFav = async () => {
    if (fav) await api.removeFavorite(fav.id); else await api.addFavorite(acte.id, acte.name);
    reloadFav().catch(() => undefined);
  };

  return (
    <div className="screen">
      <TopBar title="Détails de l'intervention" />
      <div className="screen-body narrow">
        <div className="card row" style={{ gap: 14, padding: 16 }}>
          <span className="icon-chip icon-chip--lg"><Icon name={acteIcon(acte)} size={30} stroke={1.8} /></span>
          <span className="grow stack" style={{ gap: 2 }}>
            <strong style={{ fontSize: 20 }}>{fav?.shortcut ?? acte.name}</strong>
            <span className="muted" style={{ fontSize: 13 }}>{acte.name}</span>
          </span>
          <FavoriteStar active={!!fav} onToggle={toggleFav} label={acte.name} />
        </div>

        <div className="r-2">
          <label className="row"><span className="field-label">Date</span>
            <input className="input" type="date" value={draft.date} onChange={(e) => set({ date: e.target.value })} /></label>
          <label className="row"><span className="field-label">Rôle</span>
            <select className="select" value={draft.role} onChange={(e) => set({ role: e.target.value as Role, supervisorId: e.target.value === 'SOLO' ? undefined : draft.supervisorId })}>
              {ROLES.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}{r === 'SOLO' ? ' solo' : ''}</option>)}
            </select></label>
          {supervisorLabel && (
            <label className="row"><span className="field-label">{supervisorLabel}</span>
              <select className="select" value={draft.supervisorId ?? ''} onChange={(e) => set({ supervisorId: e.target.value || undefined })}>
                <option value="">Choisir…</option>
                {(surgeons ?? []).map((s) => <option key={s.id} value={s.id}>{surgeonName(s)}</option>)}
              </select></label>
          )}
          <label className="row"><span className="field-label">Année de formation</span>
            <select className="select" value={draft.yearId} onChange={(e) => set({ yearId: e.target.value, supervisorId: undefined })}>
              {(years ?? []).map((y) => <option key={y.id} value={y.id}>{YEAR_LABEL(y.yearOfFormation)} · {y.hospital}</option>)}
            </select></label>
        </div>

        <div className="stack" style={{ gap: 8 }}>
          <span className="muted" style={{ fontSize: 13 }}>Nomenclature INAMI</span>
          <span className="input" style={{ gap: 8, display: 'flex', alignItems: 'center' }}>
            <CodeBadge code={acte.code} /><span className="grow">{acte.name}</span>
            <span className="muted small" style={{ fontWeight: 600 }}>{SPECIALITY_SHORT[acte.speciality]}</span>
          </span>
        </div>
        {draft.createdAt && (
          <span className="row muted small" style={{ gap: 6 }}><Icon name="info" size={14} />
            Encodée le {new Date(draft.createdAt).toLocaleDateString('fr-BE', { day: 'numeric', month: 'short', year: 'numeric' })} · reprise dans le carnet Excel
          </span>
        )}
        {saveError && <p className="banner banner--danger" role="alert">{saveError}</p>}

        <button type="button" className="btn btn--primary pressable" onClick={save} disabled={state === 'saving' || (!!supervisorLabel && !draft.supervisorId)}
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
