import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActivityTabs, ErrorState, Skeleton, TopBar } from '@/components/ui';
import { groupByMonth, hourLabel, hoursBetween, monthLabel, shortDay } from '@/lib/format';

/** Onglet « Activités » › Gardes (`GET /api/gardes`). */
export default function GardesListPage() {
  const { data, error, reload } = useAsync(() => api.listGardes(), []);
  const patients = data?.reduce((n, g) => n + g.number, 0) ?? 0;
  return (
    <div className="screen" style={{ position: 'relative' }}>
      <TopBar title="Mes activités" back="/" center />
      <ActivityTabs />
      <div className="screen-body screen-body--tabbed" style={{ gap: 0, paddingTop: 12 }}>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!data ? <Skeleton h={240} r={14} /> : (
          <>
            <div className="tile tile--row tone-orange">
              <Icon name="moon" size={24} />
              <span className="stack grow" style={{ gap: 0 }}>
                <span className="tile-value" style={{ fontSize: 20 }}>{data.length} garde{data.length > 1 ? 's' : ''}</span>
                <span className="tile-label" style={{ fontSize: 12 }}>{patients} patients vus en garde</span>
              </span>
            </div>
            {groupByMonth(data, (g) => g.dateOfStart).map(([month, items]) => (
              <section key={month}>
                <h2 className="month-head">{monthLabel(month + '-01')}</h2>
                <div className="list">
                  {items.map((g) => {
                    const sameDay = g.dateOfStart.slice(0, 10) === g.dateOfEnd.slice(0, 10);
                    return (
                      <Link key={g.id} to={`/gardes/${g.id}`} className="list-row">
                        <span className="grow stack" style={{ gap: 2 }}>
                          <span className="list-row-title" style={{ fontSize: 14 }}>{shortDay(g.dateOfStart.slice(0, 10))}{sameDay ? '' : ` → ${shortDay(g.dateOfEnd.slice(0, 10))}`}</span>
                          <span className="list-row-sub">{hourLabel(g.dateOfStart)} – {hourLabel(g.dateOfEnd)} · {g.number} patients</span>
                        </span>
                        <span className="code" style={{ color: '#C77506', background: 'var(--mc-warn-soft)', fontSize: 12 }}>{hoursBetween(g.dateOfStart, g.dateOfEnd)} h</span>
                      </Link>
                    );
                  })}
                </div>
              </section>
            ))}
            {data.length === 0 && <p className="muted" style={{ paddingTop: 16 }}>Aucune garde encodée.</p>}
          </>
        )}
      </div>
      <Link to="/gardes/ajouter" className="fab fab--float" aria-label="Ajouter une garde"><Icon name="plus" size={26} stroke={2.6} /></Link>
    </div>
  );
}
