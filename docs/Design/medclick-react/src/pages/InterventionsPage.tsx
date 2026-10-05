import { useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActeIcon, ErrorState, RoleDot, Skeleton, TopBar } from '@/components/ui';
import { StaggeredList } from '@/motion';
import { actesById } from '@/data/actes';
import { relativeDayLabel, yearLabel } from '@/lib/format';
import { ROLE_LABEL, type Region } from '@/types';

const FILTERS: { label: string; region?: Region }[] = [
  { label: 'Toutes' }, { label: 'Genou', region: 'genou' }, { label: 'Hanche', region: 'hanche' }, { label: 'Épaule', region: 'epaule' },
];

export default function InterventionsPage() {
  const location = useLocation();
  const saved = location.state as { createdIds?: string[]; message?: string } | null;
  const [filter, setFilter] = useState(0);
  const [q, setQ] = useState('');
  const region = FILTERS[filter].region;
  const { data, error, reload } = useAsync(() => api.listSurgeries({ region, q: q.trim() || undefined }), [region, q]);
  const newIds = useMemo(() => new Set(saved?.createdIds ?? []), [saved]);

  return (
    <div className="screen" style={{ position: 'relative' }}>
      <TopBar title="Mes interventions" back="/" center />
      <div className="screen-body screen-body--tabbed">
        {saved?.message && (
          <div className="banner banner--success mc-enter" role="status">
            <span className="row" style={{ fontWeight: 700 }}><Icon name="check" size={18} stroke={3} />{saved.message}</span>
          </div>
        )}
        <div className="r-2" style={{ alignItems: 'center' }}>
        <label className="search">
          <Icon name="search" size={18} />
          <input type="search" placeholder="Rechercher une intervention…" aria-label="Rechercher une intervention" value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
        <div className="chips">
          {FILTERS.map((f, i) => (
            <button key={f.label} type="button" className="chip" aria-pressed={i === filter} onClick={() => setFilter(i)}>{f.label}</button>
          ))}
        </div>
        </div>

        {error && <ErrorState error={error} onRetry={reload} />}
        <section>
          <h2 className="section-title" style={{ marginBottom: 4 }}>Récemment ajoutées</h2>
          {!data ? (
            <div className="stack">{[0, 1, 2, 3].map((i) => <Skeleton key={i} h={52} />)}</div>
          ) : data.length === 0 ? (
            <p className="muted">Aucune intervention trouvée.</p>
          ) : (
            <div className="list list-card">
              <div className="list-head hide-mobile" aria-hidden="true">
                <span className="row">
                  <span className="grow">Intervention</span><span style={{ width: '22%' }}>Chirurgien</span>
                  <span style={{ width: '12%' }}>Année</span><span style={{ width: 18 }} />
                </span>
              </div>
              <StaggeredList step={40}>
                {data.slice(0, 30).map((s) => {
                  const a = actesById[s.acteId];
                  return (
                    <Link key={s.id} to={`/interventions/${s.id}`} className="list-row pressable"
                      style={newIds.has(s.id) ? { background: 'var(--mc-primary-tint)', borderRadius: 10 } : undefined}>
                      <span className="row grow" style={{ gap: 12 }}>
                        <span className="row grow" style={{ gap: 12 }}>
                          <ActeIcon acte={a} />
                          <span className="grow">
                            <span className="list-row-title"><RoleDot role={s.role} />{a?.label ?? s.acteId}</span>
                            <span className="list-row-sub">{relativeDayLabel(s.date)} · {ROLE_LABEL[s.role]}</span>
                          </span>
                        </span>
                        <span className="hide-mobile list-row-sub" style={{ width: '22%' }}>{s.surgeon}</span>
                        <span className="hide-mobile list-row-sub" style={{ width: '12%' }}>{yearLabel(s.trainingYear)}</span>
                        <Icon name="chevR" size={18} />
                      </span>
                    </Link>
                  );
                })}
              </StaggeredList>
            </div>
          )}
        </section>
      </div>
      <Link to="/interventions/ajouter" className="fab fab--float" aria-label="Ajouter une intervention"><Icon name="plus" size={26} stroke={2.6} /></Link>
    </div>
  );
}
