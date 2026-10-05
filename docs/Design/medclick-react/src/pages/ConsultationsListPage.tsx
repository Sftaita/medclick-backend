import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActivityTabs, ErrorState, Skeleton, TopBar } from '@/components/ui';
import { CONSULTATION_SPECIALITY_SHORT } from '@/data/referentiel';
import { dayLabel, groupByMonth, monthLabel } from '@/lib/format';
import { DAY_PART_LABEL } from '@/types';

/** Onglet « Activités » › Consultations (`GET /api/consultations`). */
export default function ConsultationsListPage() {
  const { data, error, reload } = useAsync(() => api.listConsultations(), []);
  const patients = data?.reduce((n, c) => n + c.number, 0) ?? 0;
  return (
    <div className="screen" style={{ position: 'relative' }}>
      <TopBar title="Mes activités" back="/" center />
      <ActivityTabs />
      <div className="screen-body screen-body--tabbed" style={{ gap: 0, paddingTop: 12 }}>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!data ? <Skeleton h={240} r={14} /> : (
          <>
            <div className="tile tile--row tone-purple">
              <Icon name="stetho" size={24} />
              <span className="stack grow" style={{ gap: 0 }}>
                <span className="tile-value" style={{ fontSize: 20 }}>{data.length} consultation{data.length > 1 ? 's' : ''}</span>
                <span className="tile-label" style={{ fontSize: 12 }}>{patients} patients vus</span>
              </span>
            </div>
            {groupByMonth(data, (c) => c.date).map(([month, items]) => (
              <section key={month}>
                <h2 className="month-head">{monthLabel(month + '-01')}</h2>
                <div className="list">
                  {items.map((c) => (
                    <Link key={c.id} to={`/consultations/${c.id}`} className="list-row">
                      <span className="grow stack" style={{ gap: 2 }}>
                        <span className="list-row-title" style={{ fontSize: 14 }}>{dayLabel(c.date)} · {DAY_PART_LABEL[c.dayPart]}</span>
                        <span className="list-row-sub">{CONSULTATION_SPECIALITY_SHORT[c.speciality]}</span>
                      </span>
                      <span style={{ fontSize: 18, fontWeight: 800 }}>{c.number}</span>
                      <span className="list-row-sub" style={{ width: 50 }}>patients</span>
                    </Link>
                  ))}
                </div>
              </section>
            ))}
            {data.length === 0 && <p className="muted" style={{ paddingTop: 16 }}>Aucune consultation encodée.</p>}
          </>
        )}
      </div>
      <Link to="/consultations/ajouter" className="fab fab--float" aria-label="Ajouter une consultation"><Icon name="plus" size={26} stroke={2.6} /></Link>
    </div>
  );
}
