import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { DeltaBadge, ErrorState, Skeleton, TopBar } from '@/components/ui';
import { AnimatedNumber, Reveal, useTween } from '@/motion';
import { DURATION } from '@/motion/tokens';
import { ProgressionTabs } from './ProgressionPage';

const DONUT_COLORS = ['#2F5BEA', '#7C5CE0', '#0B1F4D', '#B8C4D6'];

// §37.14 — les graphiques se construisent une fois à l'arrivée puis restent statiques.
export default function StatisticsPage() {
  const { data: s, error, reload } = useAsync(() => api.getStatistics(), []);
  return (
    <div className="screen">
      <TopBar title="Statistiques" back="/progression" center />
      <ProgressionTabs />
      <div className="screen-body screen-body--tabbed" style={{ paddingTop: 16, gap: 14 }}>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!s ? <><Skeleton h={150} r={14} /><Skeleton h={220} r={18} /><Skeleton h={160} r={18} /></> : (
          <>
            <div className="card card--outline" style={{ padding: 10, alignItems: 'center', borderRadius: 12 }}><strong style={{ fontSize: 14 }}>{s.periodLabel}</strong></div>
            <div className="kpi-grid">
              <Stat icon="pen" tone="blue" label="interventions" v={s.year.interventions} d={s.year.deltas.interventions} />
              <Stat icon="hand" tone="green" label="première main" v={s.year.firstHand} d={s.year.deltas.firstHand} />
              <Stat icon="stetho" tone="purple" label="consultations" v={s.year.consultations} d={s.year.deltas.consultations} />
              <Stat icon="moon" tone="orange" label="gardes" v={s.year.guards} d={s.guardsDelta} />
            </div>

            <div className="split split--even">
            <figure className="card card--outline" style={{ margin: 0 }}>
              <figcaption style={{ fontSize: 14, fontWeight: 700 }}>Interventions par mois</figcaption>
              <Bars data={s.monthly} />
              <span className="muted small center">Total : <strong style={{ color: 'var(--mc-ink)' }}>{s.year.interventions}</strong> interventions</span>
            </figure>

            <figure className="card card--outline" style={{ margin: 0 }}>
              <figcaption style={{ fontSize: 14, fontWeight: 700 }}>Répartition par spécialité</figcaption>
              <div className="row" style={{ gap: 20 }}>
                <Donut parts={s.byRegion.map((r) => r.percent)} total={s.year.interventions}
                  label={s.byRegion.map((r) => `${r.region} ${r.percent} %`).join(', ')} />
                <Reveal fadeOnly delay={1100} className="grow stack" style={{ gap: 8 }}>
                  {s.byRegion.map((r, i) => (
                    <span key={r.region} className="row" style={{ fontSize: 13, gap: 8 }}>
                      <span className="dot" style={{ width: 10, height: 10, background: DONUT_COLORS[i] }} />
                      <span className="grow">{r.region}</span><strong>{r.percent} %</strong>
                    </span>
                  ))}
                </Reveal>
              </div>
            </figure>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function Stat({ icon, tone, label, v, d }: { icon: 'pen' | 'hand' | 'stetho' | 'moon'; tone: string; label: string; v: number; d: number | null }) {
  return (
    <div className={`tile tile--row tone-${tone}`}>
      <Icon name={icon} size={26} />
      <span className="stack" style={{ gap: 2 }}>
        <span className="tile-value"><AnimatedNumber value={v} /></span>
        <span className="tile-label" style={{ fontSize: 12 }}>{label}</span>
        <DeltaBadge value={d} delay={DURATION.counter} />
      </span>
    </div>
  );
}

function Bars({ data }: { data: { month: string; count: number }[] }) {
  const max = Math.max(5, ...data.map((d) => d.count));
  return (
    <div>
      <div className="row" style={{ alignItems: 'flex-end', height: 150, gap: 8, borderBottom: '1px solid #C9D4E3' }}>
        {data.map((d, i) => <Bar key={d.month} count={d.count} h={(d.count / max) * 128} delay={250 + i * 55} last={i === data.length - 1} month={d.month} />)}
      </div>
      <div className="row" style={{ gap: 8, marginTop: 6 }}>
        {data.map((d) => <span key={d.month} className="grow center muted" style={{ fontSize: 10 }}>{d.month}</span>)}
      </div>
    </div>
  );
}

function Bar({ count, h, delay, last, month }: { count: number; h: number; delay: number; last: boolean; month: string }) {
  const p = useTween(1, { from: 0, duration: DURATION.bar, delay });
  return (
    <div className="grow stack" style={{ alignItems: 'center', justifyContent: 'flex-end', gap: 4, height: '100%' }} title={`${month} : ${count} interventions`}>
      <span style={{ fontSize: 10, fontWeight: 700, opacity: p }}>{count}</span>
      <span style={{ width: '100%', maxWidth: 24, height: h, background: last ? 'var(--mc-primary)' : '#8DB4F2', borderRadius: '4px 4px 0 0', transformOrigin: 'bottom', transform: `scaleY(${p})` }} />
    </div>
  );
}

function Donut({ parts, total, label }: { parts: number[]; total: number; label: string }) {
  const p = useTween(1, { from: 0, duration: DURATION.ring, delay: 400 });
  const r = 40, C = 2 * Math.PI * r;
  const drawn = C * p;
  let acc = 0;
  return (
    <span className="ring" style={{ width: 112, height: 112 }}>
      <svg width="112" height="112" viewBox="0 0 112 112" role="img" aria-label={label}>
        <g transform="rotate(-90 56 56)" fill="none" strokeWidth="16">
          {parts.map((pc, i) => {
            const len = (C * pc) / 100;
            const vis = Math.max(0, Math.min(len - 1.2, drawn - acc));
            const off = -acc;
            acc += len;
            return <circle key={i} cx="56" cy="56" r={r} stroke={DONUT_COLORS[i]} strokeDasharray={`${vis} ${C}`} strokeDashoffset={off} />;
          })}
        </g>
      </svg>
      <span className="ring-label stack" style={{ gap: 0 }}><strong style={{ fontSize: 20 }}><AnimatedNumber value={total} delay={400} duration={DURATION.ring} /></strong><span className="muted small" style={{ fontWeight: 500 }}>total</span></span>
    </span>
  );
}
