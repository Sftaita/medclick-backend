import { Link, useParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, RoleDot, Skeleton, TopBar } from '@/components/ui';
import { StaggeredList } from '@/motion';
import { ROLE_LABEL, type DayEntry } from '@/types';

export default function DayPage() {
  const { date = '' } = useParams();
  const { data: d, error, reload } = useAsync(() => api.getDay(date), [date]);
  const title = d ? d.label.charAt(0).toUpperCase() + d.label.slice(1) : 'Journée';
  // Le formulaire d'ajout est pré-rempli avec la date du jour consulté.
  const addLink = `/interventions/ajouter?date=${date}`;

  return (
    <div className="screen">
      <TopBar title={title} center />
      <div role="tablist" className="tabs">
        <button role="tab" aria-selected="true">Vue du jour</button>
        <button role="tab" aria-selected="false">Mes notes</button>
      </div>
      <div className="screen-body" style={{ gap: 22, paddingTop: 16 }}>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!d ? <><Skeleton h={120} r={14} /><Skeleton h={120} r={14} /></> : (
          <>
            <div className="r-2">
            <HalfDay title="Matin" complete={d.morningComplete} entries={d.morning} addLink={addLink} />
            <HalfDay title="Après-midi" complete={d.afternoonComplete} entries={d.afternoon} addLink={addLink} />
            </div>
            <section className="stack">
              <h2 className="h2">Garde</h2>
              {d.guard ? <Entry e={d.guard} /> : (
                <button type="button" className="btn btn--secondary" style={{ borderStyle: 'dashed', color: 'var(--mc-muted)', borderColor: '#C9D4E3', justifyContent: 'flex-start' }}>
                  <Icon name="plus" size={18} />Aucune garde — en ajouter une
                </button>
              )}
            </section>
          </>
        )}
      </div>
    </div>
  );
}

function HalfDay({ title, complete, entries, addLink }: { title: string; complete: boolean; entries: DayEntry[]; addLink: string }) {
  return (
    <section className="stack">
      <div className="row" style={{ justifyContent: 'space-between' }}>
        <h2 className="h2">{title}</h2>
        <Link to={addLink} className="fab" style={{ position: 'static', width: 44, height: 44 }} aria-label={`Ajouter une intervention — ${title}`}><Icon name="plus" size={22} stroke={2.6} /></Link>
      </div>
      {complete && entries.length > 0 ? (
        <>
          <span className="row small" style={{ color: 'var(--mc-success)', fontWeight: 600, gap: 8 }}><Icon name="check" size={16} stroke={3} />{entries.length} élément{entries.length > 1 ? 's' : ''} encodé{entries.length > 1 ? 's' : ''}</span>
          <div className="card card--outline" style={{ padding: '0 6px 0 14px', gap: 0 }}>
            <StaggeredList step={60}>{entries.map((e) => <Entry key={e.id} e={e} />)}</StaggeredList>
          </div>
        </>
      ) : (
        <>
          <span className="row small" style={{ color: 'var(--mc-danger)', fontWeight: 600, gap: 8 }}><Icon name="alert" size={16} />Non complété</span>
          <div className="empty">
            <span className="muted" style={{ fontSize: 13 }}>Aucune intervention encodée pour cette demi-journée.</span>
            <Link to={addLink} style={{ fontWeight: 700, fontSize: 14 }}>Ajouter une intervention</Link>
          </div>
        </>
      )}
    </section>
  );
}

function Entry({ e }: { e: DayEntry }) {
  return (
    <div className="list-row" style={{ minHeight: 62 }}>
      <span style={{ color: 'var(--mc-primary)', display: 'flex' }}><Icon name={e.kind === 'consultation' ? 'stetho' : e.kind === 'guard' ? 'moon' : 'joint'} size={24} /></span>
      <span className="grow stack" style={{ gap: 2 }}>
        <span className="list-row-title" style={{ fontSize: 14 }}><RoleDot role={e.role} />{e.title}</span>
        <span className="list-row-sub">{ROLE_LABEL[e.role]}{e.time ? ` · ${e.time}` : ''}</span>
      </span>
      <button type="button" className="icon-btn" aria-label={`Options ${e.title}`}><Icon name="dots" size={20} /></button>
    </div>
  );
}
