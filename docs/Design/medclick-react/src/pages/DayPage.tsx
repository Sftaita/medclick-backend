import { Link, useParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, RoleDot, Skeleton, TopBar, acteIcon } from '@/components/ui';
import { surgeonName } from '@/components/CommonFieldsForm';
import { StaggeredList } from '@/motion';
import { CONSULTATION_SPECIALITY_SHORT } from '@/data/referentiel';
import { hourLabel, hoursBetween } from '@/lib/format';
import { DAY_PART_LABEL, ROLE_LABEL, SUPERVISOR_LABEL, type DayPart } from '@/types';

/**
 * Vue d'un jour. Le backend ne connaît la demi-journée que pour les consultations (`dayPart`) :
 * les interventions sont listées sans moment, les consultations par matin / après-midi / nuit.
 */
export default function DayPage() {
  const { date = '' } = useParams();
  const { data: d, error, reload } = useAsync(() => api.getDay(date), [date]);
  const { data: favorites } = useAsync(() => api.listFavorites(), []);
  const { data: profile } = useAsync(() => api.getProfile(), []);
  const { data: surgeons } = useAsync(() => (profile?.currentYear ? api.listSurgeons(profile.currentYear.id) : Promise.resolve([])), [profile?.currentYear?.id]);
  const title = d ? d.label.charAt(0).toUpperCase() + d.label.slice(1) : 'Journée';
  const shortcut = (acteId: string) => favorites?.find((f) => f.acte.id === acteId)?.shortcut;
  const supervisor = (id?: string) => { const s = surgeons?.find((x) => x.id === id); return s ? surgeonName(s) : ''; };

  return (
    <div className="screen">
      <TopBar title={title} center />
      <div role="tablist" className="tabs">
        <button role="tab" aria-selected="true">Vue du jour</button>
        <Link role="tab" aria-selected="false" to="/activites/formations">Formations</Link>
      </div>
      <div className="screen-body" style={{ gap: 22, paddingTop: 16 }}>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!d ? <><Skeleton h={120} r={14} /><Skeleton h={120} r={14} /></> : (
          <>
            <section className="stack">
              <div className="row" style={{ justifyContent: 'space-between' }}>
                <h2 className="h2">Interventions</h2>
                <Link to={`/interventions/ajouter?date=${date}`} className="fab" style={{ position: 'static', width: 44, height: 44 }} aria-label="Ajouter une intervention ce jour"><Icon name="plus" size={22} stroke={2.6} /></Link>
              </div>
              {d.surgeries.length > 0 ? (
                <>
                  <span className="row small" style={{ color: 'var(--mc-success)', fontWeight: 600, gap: 8 }}><Icon name="check" size={16} stroke={3} />{d.surgeries.length} intervention{d.surgeries.length > 1 ? 's' : ''} encodée{d.surgeries.length > 1 ? 's' : ''}</span>
                  <div className="card card--outline" style={{ padding: '0 6px 0 14px', gap: 0 }}>
                    <StaggeredList step={60}>
                      {d.surgeries.map((s) => {
                        const label = SUPERVISOR_LABEL[s.role];
                        return (
                          <Link key={s.id} to={`/interventions/${s.id}`} className="list-row" style={{ minHeight: 62 }}>
                            <span style={{ color: 'var(--mc-primary)', display: 'flex' }}><Icon name={acteIcon(s.acte)} size={24} /></span>
                            <span className="grow stack" style={{ gap: 2 }}>
                              <span className="list-row-title" style={{ fontSize: 14 }}><RoleDot role={s.role} />{shortcut(s.acte.id) ?? s.acte.name}</span>
                              <span className="list-row-sub">{ROLE_LABEL[s.role]}{label && supervisor(s.supervisorId) ? ` · ${label.toLowerCase()} ${supervisor(s.supervisorId)}` : ''}</span>
                            </span>
                            <Icon name="chevR" size={18} />
                          </Link>
                        );
                      })}
                    </StaggeredList>
                  </div>
                </>
              ) : (
                <div className="empty" style={{ background: 'var(--mc-bg)' }}>
                  <span className="muted" style={{ fontSize: 13 }}>Aucune intervention encodée ce jour.</span>
                </div>
              )}
            </section>

            <section className="stack">
              <div className="row" style={{ justifyContent: 'space-between' }}>
                <h2 className="h2">Consultations</h2>
                <Link to={`/consultations/ajouter?date=${date}`} className="fab" style={{ position: 'static', width: 44, height: 44 }} aria-label="Ajouter une consultation ce jour"><Icon name="plus" size={22} stroke={2.6} /></Link>
              </div>
              <div className="card card--outline" style={{ padding: '0 14px', gap: 0 }}>
                {(Object.keys(DAY_PART_LABEL) as DayPart[]).map((p, i, all) => {
                  const c = d.consultations[p];
                  return (
                    <div key={p} className="row" style={{ minHeight: 52, gap: 12, borderBottom: i < all.length - 1 ? '1px solid var(--mc-border-soft)' : 0 }}>
                      <span style={{ width: 86, fontSize: 13, fontWeight: 700, color: 'var(--mc-ink-2)' }}>{DAY_PART_LABEL[p]}</span>
                      {c ? (
                        <Link to={`/consultations/${c.id}`} className="grow row" style={{ textDecoration: 'none', color: 'var(--mc-ink)', fontSize: 14, fontWeight: 700 }}>
                          <span className="grow">{CONSULTATION_SPECIALITY_SHORT[c.speciality]} · {c.number} patients</span>
                          <span style={{ color: 'var(--mc-success)', display: 'flex' }}><Icon name="check" size={18} stroke={3} /></span>
                        </Link>
                      ) : (
                        <Link to={`/consultations/ajouter?date=${date}&moment=${p}`} className="grow" style={{ fontSize: 14, fontWeight: 600, textDecoration: 'none' }}>+ Ajouter</Link>
                      )}
                    </div>
                  );
                })}
              </div>
            </section>

            <section className="stack">
              <h2 className="h2">Garde</h2>
              {d.garde ? (
                <Link to={`/gardes/${d.garde.id}`} className="card card--outline row" style={{ flexDirection: 'row', textDecoration: 'none', color: 'var(--mc-ink)' }}>
                  <span className="icon-chip tone-orange"><Icon name="moon" size={20} /></span>
                  <span className="grow stack" style={{ gap: 2 }}>
                    <strong style={{ fontSize: 14 }}>{hourLabel(d.garde.dateOfStart)} – {hourLabel(d.garde.dateOfEnd)} · {hoursBetween(d.garde.dateOfStart, d.garde.dateOfEnd)} h</strong>
                    <span className="list-row-sub">{d.garde.number} patients vus</span>
                  </span>
                  <Icon name="chevR" size={18} />
                </Link>
              ) : (
                <Link to={`/gardes/ajouter?date=${date}`} className="btn btn--secondary" style={{ borderStyle: 'dashed', color: 'var(--mc-muted)', borderColor: '#C9D4E3', justifyContent: 'flex-start' }}>
                  <Icon name="plus" size={18} />Aucune garde — en ajouter une
                </Link>
              )}
            </section>
          </>
        )}
      </div>
    </div>
  );
}
