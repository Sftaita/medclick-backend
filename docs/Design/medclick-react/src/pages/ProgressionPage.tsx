import { NavLink } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, Skeleton, TopBar } from '@/components/ui';
import { AnimatedNumber, AnimatedProgressBar, AnimatedProgressRing, Reveal, StaggeredList } from '@/motion';
import { percent } from '@/lib/format';

export function ProgressionTabs() {
  return (
    <nav className="tabs" aria-label="Sections de progression">
      <NavLink to="/progression" end>Vue d'ensemble</NavLink>
      <NavLink to="/progression/milestones">Milestones</NavLink>
      <NavLink to="/progression/statistiques">Statistiques</NavLink>
    </nav>
  );
}

// Objectifs du carnet : à charger depuis le backend (référentiel de formation) en production.
const TARGETS = { interventions: 160, firstHand: 80, consultations: 40, guards: 30 };

export default function ProgressionPage() {
  const { data, error, reload } = useAsync(() => api.getDashboard(), []);
  const y = data?.year;
  const rows = y ? [
    { label: 'Interventions', v: y.interventions, t: TARGETS.interventions, icon: 'joint' as const, tone: 'blue', bar: 'var(--mc-primary)' },
    { label: 'Première main', v: y.firstHand, t: TARGETS.firstHand, icon: 'hand' as const, tone: 'green', bar: '#14A37F' },
    { label: 'Consultations', v: y.consultations, t: TARGETS.consultations, icon: 'stetho' as const, tone: 'purple', bar: 'var(--mc-purple)' },
    { label: 'Gardes', v: y.guards, t: TARGETS.guards, icon: 'moon' as const, tone: 'orange', bar: '#F0A12B' },
  ] : [];

  return (
    <div className="screen">
      <TopBar title="Progression" back="/" center />
      <ProgressionTabs />
      <div className="screen-body screen-body--tabbed" style={{ paddingTop: 16 }}>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!y ? <><Skeleton h={150} r={18} /><Skeleton h={260} r={18} /></> : (
          <div className="split">
            <Reveal className="card split-main">
              <span className="section-title" style={{ fontSize: 14 }}><Icon name="notebook" size={18} />Mon carnet de stage</span>
              <div className="row" style={{ gap: 18 }}>
                <AnimatedProgressRing value={y.completion} size={100} stroke={10} pulseOnDone={y.completion >= 70}>
                  {(v) => <span style={{ fontSize: 22 }} className="tabular">{Math.round(v)} %</span>}
                </AnimatedProgressRing>
                <span className="grow stack" style={{ gap: 6 }}>
                  <span style={{ fontSize: 28, fontWeight: 800 }}>
                    <AnimatedNumber value={y.interventions} /> <span style={{ fontSize: 20, fontWeight: 600, color: 'var(--mc-muted)' }}>/ {y.completionTarget}</span>
                  </span>
                  <span className="muted" style={{ fontSize: 13 }}>interventions validées</span>
                  <AnimatedProgressBar value={percent(y.interventions, y.completionTarget)} />
                </span>
              </div>
            </Reveal>

            <section className="stack split-side">
              <h2 className="section-title">Par type d'activité</h2>
              <StaggeredList start={150} step={70}>
                {rows.map((r, i) => (
                  <div key={r.label} className="card card--outline row" style={{ flexDirection: 'row', padding: '12px 14px' }}>
                    <span className={`icon-chip icon-chip--sq tone-${r.tone}`}><Icon name={r.icon} size={20} /></span>
                    <span className="grow stack" style={{ gap: 6 }}>
                      <span className="row" style={{ justifyContent: 'space-between', fontSize: 13 }}>
                        <strong>{r.label}</strong><span className="muted">{r.v} / {r.t}</span>
                      </span>
                      <AnimatedProgressBar value={percent(r.v, r.t)} color={r.bar} delay={200 + i * 70} height={7} />
                    </span>
                    <strong style={{ width: 40, textAlign: 'right', fontSize: 13 }}>{percent(r.v, r.t)}%</strong>
                  </div>
                ))}
              </StaggeredList>
            </section>
          </div>
        )}
      </div>
    </div>
  );
}
