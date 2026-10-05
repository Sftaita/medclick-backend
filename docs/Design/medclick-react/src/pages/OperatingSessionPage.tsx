import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '@/api';
import { Icon } from '@/components/Icon';
import { ActeIcon, TopBar } from '@/components/ui';
import { CommonFieldsForm } from '@/components/CommonFieldsForm';
import { SaveResult } from '@/components/SaveResult';
import { Collapsible, CountStepper } from '@/motion';
import { ACTES, actesById, SURGEONS } from '@/data/actes';
import { todayISO } from '@/lib/format';
import { ROLE_SHORT, ROLES, type BatchResponse, type CommonFields, type Role } from '@/types';

interface Line { key: string; acteId: string; quantity: number; role: Role; removing?: boolean }

/* §7 TER — journée opératoire : plusieurs actes différents, infos communes saisies une fois. */
export default function OperatingSessionPage() {
  const nav = useNavigate();
  const [common, setCommon] = useState<CommonFields>({ date: todayISO(), surgeon: SURGEONS[0], trainingYear: 3 });
  const [lines, setLines] = useState<Line[]>([]);
  const [picker, setPicker] = useState(true);
  const [phase, setPhase] = useState<'form' | 'saving' | 'done'>('form');
  const [result, setResult] = useState<BatchResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const active = lines.filter((l) => !l.removing);
  const total = active.reduce((n, l) => n + l.quantity, 0);
  const used = new Set(active.map((l) => l.acteId));
  const update = (key: string, p: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...p } : l)));

  const save = async () => {
    setPhase('saving');
    setError(null);
    try {
      const res = await api.createBatch({
        common,
        lines: active.map((l) => ({ acteId: l.acteId, quantity: l.quantity, roles: Array<Role>(l.quantity).fill(l.role) })),
      });
      setResult(res);
      setPhase('done');
    } catch (e) {
      setError((e as Error).message || "L'enregistrement a échoué. Aucune intervention n'a été créée.");
      setPhase('form');
    }
  };

  if (phase === 'done' && result) {
    const n = result.created.length;
    return (
      <div className="screen">
        <TopBar title="Journée opératoire" back="/interventions" />
        <SaveResult result={result} title={`${n} intervention${n > 1 ? 's' : ''} enregistrée${n > 1 ? 's' : ''}`}
          actions={<>
            <button type="button" className="btn btn--primary"
              onClick={() => nav('/interventions', { state: { createdIds: result.created.map((c) => c.id), message: `${n} interventions enregistrées` } })}>
              Voir les interventions
            </button>
            <button type="button" className="btn btn--secondary" onClick={() => { setLines([]); setPicker(true); setResult(null); setPhase('form'); }}>
              Encoder une autre session
            </button>
          </>}
        />
      </div>
    );
  }

  return (
    <div className="screen">
      <TopBar title="Journée opératoire" />
      <div className="screen-body">
        <nav className="segmented" aria-label="Mode d'encodage">
          <Link to="/interventions/ajouter">Une intervention</Link>
          <span aria-current="page">Journée opératoire</span>
        </nav>

        <div className="split split--even">
        <div className="split-side split-side--sticky">
        <section className="card card--tint" style={{ gap: 0, paddingBlock: 6 }}>
          <span className="eyebrow" style={{ padding: '8px 0 2px' }}>Commun à toute la session</span>
          <CommonFieldsForm value={common} onChange={setCommon} showNomenclature={false} />
        </section>
        </div>

        <section className="split-main stack">
          <div className="row" style={{ justifyContent: 'space-between' }}>
            <h2 className="section-title">Actes réalisés</h2>
            <span className="muted small" style={{ fontWeight: 600 }}>{total} intervention{total > 1 ? 's' : ''}</span>
          </div>

          {lines.map((l) => {
            const a = actesById[l.acteId];
            return (
              <Collapsible key={l.key} removing={!!l.removing} onRemoved={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}>
                <div className="card card--outline mc-enter" style={{ padding: 12, gap: 10, marginBottom: 2 }}>
                  <div className="row">
                    <ActeIcon acte={a} size={18} />
                    <span className="grow" style={{ fontWeight: 700 }}>{a.label}</span>
                    <CountStepper value={l.quantity} label={`Quantité de ${a.label}`} onChange={(q) => update(l.key, { quantity: q })} />
                  </div>
                  <div className="row">
                    <div className="segmented segmented--sm grow" role="group" aria-label={`Rôle pour ${a.label}`}>
                      {ROLES.map((r) => (
                        <button key={r} type="button" aria-pressed={r === l.role} onClick={() => update(l.key, { role: r })}>{ROLE_SHORT[r]}</button>
                      ))}
                    </div>
                    <button type="button" className="icon-btn" aria-label={`Retirer ${a.label}`} style={{ color: 'var(--mc-faint)' }}
                      onClick={() => update(l.key, { removing: true })}><Icon name="trash" size={18} /></button>
                  </div>
                </div>
              </Collapsible>
            );
          })}

          {picker ? (
            <div className="card card--outline" style={{ borderStyle: 'dashed', borderColor: '#9DBDF2', background: '#F9FBFF' }}>
              <div className="row" style={{ justifyContent: 'space-between' }}>
                <span className="muted small" style={{ fontWeight: 700 }}>Choisir un acte</span>
                {lines.length > 0 && <button type="button" className="btn btn--ghost btn--sm" onClick={() => setPicker(false)}>Fermer</button>}
              </div>
              <div className="chips">
                {ACTES.filter((a) => !used.has(a.id)).map((a) => (
                  <button key={a.id} type="button" className="chip chip--add"
                    onClick={() => { setLines((ls) => [...ls, { key: crypto.randomUUID(), acteId: a.id, quantity: 1, role: 'FIRST_HAND' }]); setPicker(false); }}>
                    + {a.label}
                  </button>
                ))}
              </div>
            </div>
          ) : (
            <button type="button" className="btn btn--secondary" style={{ borderStyle: 'dashed' }} onClick={() => setPicker(true)}>
              <Icon name="plus" size={18} stroke={2.6} />Ajouter un acte
            </button>
          )}
        </section>
        </div>

        {error && <p className="banner banner--danger" role="alert">{error}</p>}
      </div>

      <div className="bottom-action">
        <button type="button" className="btn btn--primary pressable" disabled={total === 0 || phase === 'saving'} onClick={save}>
          {phase === 'saving' ? <><span className="spinner" />Enregistrement…</>
            : total === 0 ? 'Ajoutez un acte'
            : total === 1 ? "Enregistrer l'intervention"
            : `Enregistrer les ${total} interventions`}
        </button>
        {total > 0 && <span className="muted small center">{total} enregistrements distincts · tout ou rien</span>}
      </div>
    </div>
  );
}
