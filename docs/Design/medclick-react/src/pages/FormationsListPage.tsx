import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActivityTabs, ErrorState, Skeleton, TopBar } from '@/components/ui';
import { hourLabel, shortDay, todayISO } from '@/lib/format';
import { FORMATION_EVENT_LABEL, FORMATION_ROLE_LABEL, type Formation, type FormationEvent } from '@/types';

const EVENT_TONE: Record<FormationEvent, string> = { congres: 'blue', staff: 'purple', journal: 'green', lesson: 'orange' };

/** Onglet « Activités » › Formations (`GET /api/formations`). */
export default function FormationsListPage() {
  const { data, error, reload } = useAsync(() => api.listFormations(), []);
  const { data: profile } = useAsync(() => api.getProfile(), []);
  const [filter, setFilter] = useState<FormationEvent | undefined>(undefined);
  const list = (data ?? []).filter((f) => !filter || f.event === filter);
  const t = todayISO();
  const upcoming = list.filter((f) => f.dateOfStart.slice(0, 10) >= t).reverse();
  const past = list.filter((f) => f.dateOfStart.slice(0, 10) < t);
  const hospital = profile?.currentYear?.hospital ?? "Hôpital de stage";

  const row = (f: Formation) => (
    <Link key={f.id} to={`/formations/${f.id}`} className="list-row" style={{ minHeight: 72 }}>
      <span className={`code tone-${EVENT_TONE[f.event]}`} style={{ flex: 'none' }}>{FORMATION_EVENT_LABEL[f.event]}</span>
      <span className="grow stack" style={{ gap: 3 }}>
        <span className="list-row-title" style={{ fontSize: 14 }}>{f.name}</span>
        <span className="list-row-sub">
          {shortDay(f.dateOfStart.slice(0, 10))} · {hourLabel(f.dateOfStart)} · {f.local ? hospital : f.location} · {FORMATION_ROLE_LABEL[f.role]}
        </span>
      </span>
      <Icon name="chevR" size={18} />
    </Link>
  );

  return (
    <div className="screen" style={{ position: 'relative' }}>
      <TopBar title="Mes activités" back="/" center />
      <ActivityTabs />
      <div className="screen-body screen-body--tabbed" style={{ gap: 0, paddingTop: 12 }}>
        <div className="chips" role="group" aria-label="Filtrer par type">
          <button type="button" className="chip" aria-pressed={!filter} onClick={() => setFilter(undefined)}>Tous</button>
          {(Object.keys(FORMATION_EVENT_LABEL) as FormationEvent[]).map((e) => (
            <button key={e} type="button" className="chip" aria-pressed={e === filter} onClick={() => setFilter(e)}>{FORMATION_EVENT_LABEL[e]}</button>
          ))}
        </div>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!data ? <Skeleton h={240} r={14} /> : (
          <>
            {upcoming.length > 0 && <section><h2 className="month-head">À venir</h2><div className="list">{upcoming.map(row)}</div></section>}
            {past.length > 0 && <section><h2 className="month-head">Passées</h2><div className="list">{past.map(row)}</div></section>}
            {list.length === 0 && <p className="muted" style={{ paddingTop: 16 }}>Aucune formation encodée.</p>}
          </>
        )}
      </div>
      <Link to="/formations/ajouter" className="fab fab--float" aria-label="Ajouter une formation"><Icon name="plus" size={26} stroke={2.6} /></Link>
    </div>
  );
}
