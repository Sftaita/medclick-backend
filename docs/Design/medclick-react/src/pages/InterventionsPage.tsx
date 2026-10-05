import { useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActeIcon, ActivityTabs, ErrorState, RoleDot, Skeleton, TopBar } from '@/components/ui';
import { StaggeredList } from '@/motion';
import { REGION_LABEL, SPECIALITIES, SPECIALITY_SHORT, YEAR_LABEL } from '@/data/referentiel';
import { relativeDayLabel } from '@/lib/format';
import { ROLE_LABEL, type Speciality } from '@/types';

/** Onglet « Activités » › Interventions. Filtre par spécialité de la nomenclature. */
export default function InterventionsPage() {
  const location = useLocation();
  const saved = location.state as { createdIds?: string[]; message?: string } | null;
  const [speciality, setSpeciality] = useState<Speciality | undefined>(undefined);
  const [q, setQ] = useState('');
  const { data, error, reload } = useAsync(() => api.listSurgeries({ speciality, q: q.trim() || undefined }), [speciality, q]);
  const { data: favorites } = useAsync(() => api.listFavorites(), []);
  const { data: years } = useAsync(() => api.listYears(), []);
  const newIds = useMemo(() => new Set(saved?.createdIds ?? []), [saved]);
  const shortcut = (acteId: string) => favorites?.find((f) => f.acte.id === acteId)?.shortcut;
  const yearOf = (id: string) => years?.find((y) => y.id === id);

  return (
    <div className="screen" style={{ position: 'relative' }}>
      <TopBar title="Mes activités" back="/" center />
      <ActivityTabs />
      <div className="screen-body screen-body--tabbed" style={{ paddingTop: 12 }}>
        {saved?.message && (
          <div className="banner banner--success mc-enter" role="status">
            <span className="row" style={{ fontWeight: 700 }}><Icon name="check" size={18} stroke={3} />{saved.message}</span>
          </div>
        )}
        <div className="r-2" style={{ alignItems: 'center' }}>
          <label className="search">
            <Icon name="search" size={18} />
            <input type="search" placeholder="Rechercher une intervention ou un code…" aria-label="Rechercher une intervention" value={q} onChange={(e) => setQ(e.target.value)} />
          </label>
          <div className="chips chips--scroll" role="group" aria-label="Filtrer par spécialité">
            <button type="button" className="chip" aria-pressed={!speciality} onClick={() => setSpeciality(undefined)}>Toutes</button>
            {SPECIALITIES.map((s) => (
              <button key={s} type="button" className="chip" aria-pressed={s === speciality} onClick={() => setSpeciality(s)}>{SPECIALITY_SHORT[s]}</button>
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
                  <span className="grow">Intervention</span><span style={{ width: '18%' }}>Spécialité</span>
                  <span style={{ width: '14%' }}>Année</span><span style={{ width: 18 }} />
                </span>
              </div>
              <StaggeredList step={40}>
                {data.slice(0, 30).map((s) => {
                  const y = yearOf(s.yearId);
                  const where = s.acte.region ? REGION_LABEL[s.acte.region] : SPECIALITY_SHORT[s.acte.speciality];
                  return (
                    <Link key={s.id} to={`/interventions/${s.id}`} className="list-row pressable"
                      style={newIds.has(s.id) ? { background: 'var(--mc-primary-tint)', borderRadius: 10 } : undefined}>
                      <span className="row grow" style={{ gap: 12 }}>
                        <span className="row grow" style={{ gap: 12 }}>
                          <ActeIcon acte={s.acte} />
                          <span className="grow">
                            <span className="list-row-title"><RoleDot role={s.role} />{shortcut(s.acte.id) ?? s.acte.name}</span>
                            <span className="list-row-sub">{relativeDayLabel(s.date)} · {ROLE_LABEL[s.role]} · {where}</span>
                          </span>
                        </span>
                        <span className="hide-mobile list-row-sub" style={{ width: '18%' }}>{SPECIALITY_SHORT[s.acte.speciality]}</span>
                        <span className="hide-mobile list-row-sub" style={{ width: '14%' }}>{y ? YEAR_LABEL(y.yearOfFormation) : ''}</span>
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
