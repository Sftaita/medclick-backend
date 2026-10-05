import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActeIcon, Skeleton, TopBar } from '@/components/ui';
import { CommonFieldsForm } from '@/components/CommonFieldsForm';
import { SaveResult } from '@/components/SaveResult';
import { CountStepper } from '@/motion';
import { todayISO } from '@/lib/format';
import { ROLE_SHORT, ROLES, SUPERVISOR_LABEL, type Acte, type BatchResponse, type CommonFields, type Role } from '@/types';

/* §7 BIS — encodage d'une ou plusieurs interventions identiques.
   Quantité 1 par défaut ; « Personnaliser » permet un rôle différent par intervention (facultatif).
   Le superviseur demandé dépend du rôle : « Aidé par » (1re main aidée) ou « 1re main » (2e main). */
export default function AddInterventionPage() {
  const nav = useNavigate();
  const location = useLocation();
  const [params] = useSearchParams();
  const picked = (location.state as { acte?: Acte } | null)?.acte;
  const { data: favorites } = useAsync(() => api.listFavorites(), []);
  const { data: profile } = useAsync(() => api.getProfile(), []);
  const [acte, setActe] = useState<{ acte: Acte; label: string } | null>(picked ? { acte: picked, label: picked.name } : null);
  const [qty, setQty] = useState(1);
  const [role, setRole] = useState<Role>('SOLO');
  const [custom, setCustom] = useState(false);
  const [roles, setRoles] = useState<Role[]>(['SOLO']);
  const [common, setCommon] = useState<CommonFields>({ date: params.get('date') ?? todayISO(), yearId: '' });
  const [phase, setPhase] = useState<'form' | 'saving' | 'done'>('form');
  const [result, setResult] = useState<BatchResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Année en cours par défaut ; premier favori sélectionné si rien n'a été choisi.
  useEffect(() => { if (profile?.currentYear && !common.yearId) setCommon((c) => ({ ...c, yearId: profile.currentYear!.id })); }, [profile, common.yearId]);
  useEffect(() => { if (!acte && favorites?.[0]) setActe({ acte: favorites[0].acte, label: favorites[0].shortcut }); }, [favorites, acte]);
  // Garde le tableau des rôles aligné sur la quantité.
  useEffect(() => {
    setRoles((r) => {
      const next = r.slice(0, qty);
      while (next.length < qty) next.push(role);
      return next;
    });
  }, [qty, role]);

  const finalRoles = custom ? roles : Array<Role>(qty).fill(role);
  const needsSupervisor = finalRoles.some((r) => r !== 'SOLO');
  const supervisorLabel = custom ? (needsSupervisor ? 'Superviseur' : null) : SUPERVISOR_LABEL[role];
  const missingSupervisor = needsSupervisor && !common.supervisorId;
  const saveLabel = qty === 1 ? 'Enregistrer' : `Enregistrer ${qty} interventions`;
  const list = favorites ?? [];
  const pickedOutsideFavorites = acte && !list.some((f) => f.acte.id === acte.acte.id);

  const save = async () => {
    if (!acte) return;
    setPhase('saving');
    setError(null);
    try {
      const res = await api.createBatch({
        common: { ...common, supervisorId: needsSupervisor ? common.supervisorId : undefined },
        lines: [{ acteId: acte.acte.id, quantity: qty, roles: finalRoles }],
      });
      setResult(res); // l'animation de succès ne démarre qu'ici, après confirmation backend (§37.8)
      setPhase('done');
    } catch (e) {
      setError((e as Error).message || "L'enregistrement a échoué. Aucune intervention n'a été créée.");
      setPhase('form');
    }
  };

  const reset = () => { setQty(1); setCustom(false); setRole('SOLO'); setResult(null); setPhase('form'); };

  if (phase === 'done' && result && acte) {
    const n = result.created.length;
    return (
      <div className="screen">
        <TopBar title="Ajouter une intervention" back="/activites" />
        <SaveResult
          result={result}
          title={n === 1 ? `Intervention ${acte.label} enregistrée` : `${n} ${acte.label} enregistrées`}
          actions={<>
            <button type="button" className="btn btn--primary" onClick={reset}><Icon name="plus" size={18} stroke={2.6} />Ajouter une autre intervention</button>
            <button type="button" className="btn btn--secondary"
              onClick={() => nav('/activites', { state: { createdIds: result.created.map((c) => c.id), message: `${n} intervention${n > 1 ? 's' : ''} ${acte.label} enregistrée${n > 1 ? 's' : ''}` } })}>
              Voir les interventions
            </button>
          </>}
        />
      </div>
    );
  }

  return (
    <div className="screen">
      <TopBar title="Ajouter une intervention" />
      <div className="screen-body">
        <nav className="segmented" aria-label="Mode d'encodage">
          <span aria-current="page">Une intervention</span>
          <Link to="/interventions/journee" replace>Journée opératoire</Link>
        </nav>

        <div className="split">
        <section className="split-main stack">
          <h2 className="section-title"><Icon name="starFilled" size={18} />Mes favoris</h2>
          {!favorites ? <Skeleton h={160} r={14} /> : (
            <div className="acte-grid">
              {pickedOutsideFavorites && (
                <button type="button" className="acte pressable" aria-pressed="true">
                  <ActeIcon acte={acte.acte} size={18} />
                  <span className="grow">{acte.label}</span>
                  <span className="acte-check"><Icon name="check" size={14} stroke={3} /></span>
                </button>
              )}
              {list.map((f) => {
                const on = acte?.acte.id === f.acte.id;
                return (
                  <button key={f.id} type="button" className="acte pressable" aria-pressed={on} onClick={() => setActe({ acte: f.acte, label: f.shortcut })}>
                    <ActeIcon acte={f.acte} size={18} />
                    <span className="grow">{f.shortcut}</span>
                    {on && <span className="acte-check"><Icon name="check" size={14} stroke={3} /></span>}
                  </button>
                );
              })}
            </div>
          )}
          <Link to="/nomenclature" className="btn btn--ghost"><Icon name="search" size={18} />Chercher dans la nomenclature INAMI</Link>
        </section>

        <div className="split-side split-side--sticky">
        <section className="card card--tint">
          <div className="row" style={{ justifyContent: 'space-between' }}>
            <span className="stack" style={{ gap: 2 }}>
              <span className="eyebrow">Quantité</span>
              <span style={{ fontSize: 17, fontWeight: 700 }}>{acte?.label ?? '—'}</span>
            </span>
            <CountStepper value={qty} onChange={setQty} label={`Quantité de ${acte?.label ?? 'interventions'}`} />
          </div>
          <span className="muted" style={{ fontSize: 13 }}>
            {qty === 1 ? 'Réalisée plusieurs fois aujourd’hui ? Appuyez sur +.' : `${qty} interventions distinctes seront créées, avec les mêmes détails.`}
          </span>
          {qty > 1 && !custom && (
            <button type="button" className="btn btn--secondary btn--sm" style={{ borderStyle: 'dashed', justifyContent: 'flex-start' }}
              onClick={() => { setRoles(Array(qty).fill(role)); setCustom(true); }}>
              <Icon name="sliders" size={18} /><span className="grow" style={{ textAlign: 'left' }}>Personnaliser les {qty} interventions</span>
              <span className="muted small" style={{ fontWeight: 500 }}>facultatif</span>
            </button>
          )}
        </section>

        {custom && (
          <section className="stack">
            <div className="row" style={{ justifyContent: 'space-between' }}>
              <h2 className="section-title">Rôle par intervention</h2>
              <button type="button" className="btn btn--ghost btn--sm" onClick={() => setCustom(false)}>Même rôle pour toutes</button>
            </div>
            {roles.map((r, i) => (
              <div key={i} className="card card--outline" style={{ padding: 12, gap: 8 }}>
                <span className="row" style={{ gap: 8, fontWeight: 700 }}>
                  <span className="icon-chip" style={{ width: 24, height: 24, borderRadius: 8, background: 'var(--mc-ink)', color: '#fff', fontSize: 12 }}>{i + 1}</span>
                  {acte?.label} {i + 1}
                </span>
                <RolePills value={r} onChange={(v) => setRoles((rs) => rs.map((x, j) => (j === i ? v : x)))} small />
              </div>
            ))}
            <span className="muted small">Date, superviseur et année restent communs.</span>
          </section>
        )}

        <section className="stack" style={{ gap: 4 }}>
          <h2 className="section-title" style={{ marginBottom: 6 }}><Icon name="notebook" size={18} />Détails communs</h2>
          {!custom && (
            <div className="stack" style={{ gap: 8, paddingBottom: 12, borderBottom: '1px solid var(--mc-border-soft)' }}>
              <span className="muted" style={{ fontSize: 14 }}>{qty > 1 ? `Rôle (identique pour les ${qty})` : 'Rôle'}</span>
              <RolePills value={role} onChange={setRole} />
            </div>
          )}
          <CommonFieldsForm value={common} onChange={setCommon} supervisorLabel={supervisorLabel}
            supervisorHint={custom && needsSupervisor ? 'Enregistré comme « aidé par » (1re main aidée) ou comme 1re main (quand vous êtes 2e main).' : undefined} />
        </section>
        </div>
        </div>

        {error && <p className="banner banner--danger" role="alert">{error}</p>}
      </div>

      <div className="bottom-action">
        <button type="button" className="btn btn--primary pressable" onClick={save}
          disabled={phase === 'saving' || !acte || !common.yearId || missingSupervisor} aria-busy={phase === 'saving'}>
          {phase === 'saving' ? <><span className="spinner" />Enregistrement…</>
            : missingSupervisor ? `Choisissez « ${supervisorLabel} »`
            : <>{saveLabel}<Icon name="arrowR" size={18} stroke={2.4} /></>}
        </button>
        <span className="row muted small" style={{ justifyContent: 'center', gap: 6 }}><Icon name="bolt" size={14} />Encodage en quelques secondes</span>
      </div>
    </div>
  );
}

export function RolePills({ value, onChange, small = false }: { value: Role; onChange: (r: Role) => void; small?: boolean }) {
  return (
    <div className="pill-group" role="group" aria-label="Rôle">
      {ROLES.map((r) => (
        <button key={r} type="button" className="pill" aria-pressed={r === value} style={small ? { height: 40, fontSize: 13 } : undefined}
          onClick={() => onChange(r)}>{ROLE_SHORT[r]}</button>
      ))}
    </div>
  );
}
