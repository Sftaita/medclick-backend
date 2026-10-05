import { useState } from 'react';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, Skeleton, TopBar } from '@/components/ui';
import { AnimatedProgressBar, Reveal, StaggeredList } from '@/motion';
import { ProgressionTabs } from './ProgressionPage';
import { percent } from '@/lib/format';

const FILTERS = ['Tous', 'À venir', 'Validés'] as const;

// §37.6 : ici les badges sont simplement listés — aucune célébration ne se rejoue.
export default function MilestonesPage() {
  const { data, error, reload } = useAsync(() => api.getMilestones(), []);
  const [f, setF] = useState<(typeof FILTERS)[number]>('Tous');
  const done = data?.filter((m) => m.achievedAt).length ?? 0;
  const list = (data ?? []).filter((m) => f === 'Tous' || (f === 'Validés' ? !!m.achievedAt : !m.achievedAt));

  return (
    <div className="screen">
      <TopBar title="Mes milestones" back="/progression" center />
      <ProgressionTabs />
      <div className="screen-body screen-body--tabbed" style={{ paddingTop: 16, gap: 14 }}>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!data ? <Skeleton h={110} r={18} /> : (
          <Reveal className="card card--outline">
            <strong style={{ fontSize: 14 }}>Mes accomplissements</strong>
            <span className="row" style={{ alignItems: 'baseline', gap: 8 }}>
              <span style={{ fontSize: 28, fontWeight: 800, color: 'var(--mc-success)' }}>{done} / {data.length}</span>
              <span className="muted" style={{ fontSize: 13 }}>milestones validés</span>
            </span>
            <AnimatedProgressBar value={percent(done, data.length)} color="#14A37F" />
          </Reveal>
        )}
        <div className="chips">
          {FILTERS.map((x) => <button key={x} type="button" className="chip" aria-pressed={x === f} onClick={() => setF(x)}>{x}</button>)}
        </div>
        <div className="r-3" style={{ gap: 8 }}>
          <StaggeredList step={50}>
            {list.map((m) => {
              const ok = !!m.achievedAt;
              const showBar = !ok && m.metric !== 'custom' && m.threshold > 1;
              return (
                <div key={m.id} className="card card--outline row" style={{ flexDirection: 'row', padding: '12px 14px', background: ok ? '#F2FAF6' : '#fff', borderColor: ok ? '#D3EEDF' : undefined }}>
                  <span className="icon-chip icon-chip--sq" style={{ background: '#fff', color: m.icon === 'trophy' || m.icon === 'moon' ? '#C77506' : 'var(--mc-primary)', width: 38, height: 38 }}>
                    <Icon name={m.icon} size={22} />
                  </span>
                  <span className="grow stack" style={{ gap: 2 }}>
                    <strong style={{ fontSize: 14 }}>{m.title}</strong>
                    <span className="muted small">{ok && m.achievedAt ? `Obtenu le ${new Date(m.achievedAt).toLocaleDateString('fr-BE')}` : m.subtitle}</span>
                    {showBar && <span style={{ marginTop: 4 }}><AnimatedProgressBar value={percent(m.progress, m.threshold)} color="var(--mc-purple)" height={6} /></span>}
                  </span>
                  {ok ? (
                    <span className="icon-chip" aria-label="Validé" style={{ width: 26, height: 26, background: 'var(--mc-success)', color: '#fff' }}><Icon name="check" size={15} stroke={3} /></span>
                  ) : showBar ? (
                    <span className="small muted" style={{ fontWeight: 700 }}>{m.progress} / {m.threshold}</span>
                  ) : <Icon name="chevR" size={18} />}
                </div>
              );
            })}
          </StaggeredList>
        </div>
      </div>
    </div>
  );
}
