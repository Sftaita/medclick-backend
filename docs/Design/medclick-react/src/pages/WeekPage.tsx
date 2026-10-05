import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, Skeleton, TopBar } from '@/components/ui';
import { AnimatedNumber, AnimatedProgressRing, Reveal, StaggeredList } from '@/motion';
import { DURATION } from '@/motion/tokens';
import { todayISO } from '@/lib/format';

/* §37.11 — ordre visuel : résumé positif → complétude → problème restant → action.
   §37.12 — passage à 100 % : anneau qui se termine + impulsion, "Semaine complète", série +1. */
const SEEN_KEY = (start: string) => `mc.week.${start}.seen`;
const readSeen = (k: string) => { try { const v = localStorage.getItem(k); return v === null ? null : Number(v); } catch { return null; } };
const writeSeen = (k: string, v: number) => { try { localStorage.setItem(k, String(v)); } catch { /* stockage indisponible */ } };

export default function WeekPage() {
  const [offset, setOffset] = useState(0);
  const { data: w, error, reload } = useAsync(() => api.getWeek(offset), [offset]);

  // Valeur de complétude déjà vue : permet d'animer 94 → 100 une seule fois.
  const previous = useMemo(() => (w ? readSeen(SEEN_KEY(w.start)) : null), [w]);
  const justCompleted = !!w && w.completion === 100 && previous !== null && previous < 100;
  useEffect(() => { if (w) writeSeen(SEEN_KEY(w.start), w.completion); }, [w]);

  const streakUp = !!w && w.streak.weeks > w.streak.acknowledgedWeeks;
  useEffect(() => { if (w && streakUp) api.acknowledgeStreak(w.streak.weeks).catch(() => undefined); }, [w, streakUp]);

  const ringFrom = justCompleted ? previous! : 0;
  const ringDone = DURATION.ring + 300;
  const incomplete = w?.days.find((d) => d.status === 'incomplete');

  return (
    <div className="screen">
      <TopBar title="Ma semaine" back="/" center right={<Link to={`/jour/${todayISO()}`} className="icon-btn" aria-label="Aujourd'hui"><Icon name="calendar" size={20} /></Link>} />
      <div className="screen-body screen-body--tabbed" style={{ gap: 14 }}>
        <div className="row card card--outline" style={{ padding: 0, justifyContent: 'space-between', flexDirection: 'row', borderRadius: 12 }}>
          <button type="button" className="icon-btn" aria-label="Semaine précédente" onClick={() => setOffset((o) => o - 1)}><Icon name="chevL" size={18} /></button>
          <span style={{ fontSize: 14, fontWeight: 600 }}>{w?.label ?? ' '}</span>
          <button type="button" className="icon-btn" aria-label="Semaine suivante" disabled={offset >= 0} onClick={() => setOffset((o) => o + 1)}><Icon name="chevR" size={18} /></button>
        </div>

        {error && <ErrorState error={error} onRetry={reload} />}
        {!w ? (
          <><div className="tiles-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} h={66} r={14} />)}</div><Skeleton h={140} r={18} /></>
        ) : (
          <>
            <div className="split">
            <div className="split-main">
            {/* 1. Résumé positif */}
            <div className="tiles-4">
              <StaggeredList step={50}>
                <Tile tone="blue" icon="pen" value={w.interventions} label="interventions" />
                <Tile tone="green" icon="hand" value={w.firstHand} label="en première main" />
                <Tile tone="purple" icon="stetho" value={w.consultations} label="consultations" />
                <Tile tone="orange" icon="moon" value={w.guards} label={w.guards > 1 ? 'gardes' : 'garde'} />
              </StaggeredList>
            </div>

            {/* 2. Complétude */}
            <Reveal delay={200} className="card card--outline row" style={{ flexDirection: 'row', gap: 16 }}>
              <AnimatedProgressRing key={w.start} value={w.completion} from={ringFrom} size={104} stroke={11} delay={300}
                pulseOnDone={w.completion >= 90}>
                {(v) => <span style={{ fontSize: 24 }} className="tabular">{Math.round(v)} %</span>}
              </AnimatedProgressRing>
              <span className="stack" style={{ gap: 6 }}>
                <Reveal fadeOnly delay={900}><span style={{ fontSize: 14, color: 'var(--mc-ink-2)' }}>Votre carnet est à jour à</span></Reveal>
                <Reveal fadeOnly delay={900}><span className="tabular" style={{ fontSize: 30, fontWeight: 800, color: 'var(--mc-success)' }}>{w.completion} %</span></Reveal>
                {w.completion === 100 && (
                  <Reveal as="span" delay={justCompleted ? ringDone + 150 : 1000} className="delta" style={{ fontSize: 12, padding: '4px 8px' }}>✓ Semaine complète</Reveal>
                )}
              </span>
            </Reveal>

            {(justCompleted || streakUp) && (
              <Reveal delay={ringDone + 350} className="streak">
                <span className={'streak-flame ' + (streakUp ? 'mc-flame' : '')} style={{ animationDelay: `${ringDone + 450}ms` }}><Icon name="flame" size={20} /></span>
                <span>
                  {streakUp ? <AnimatedNumber value={w.streak.weeks} from={w.streak.acknowledgedWeeks} delay={ringDone + 450} duration={300} /> : w.streak.weeks}
                  {' '}semaines consécutives à jour
                </span>
              </Reveal>
            )}

            </div>
            <div className="split-side">
            <div className="list list-card">
              <StaggeredList start={500} step={70}>
                {w.days.map((d) => (
                  <Link key={d.date} to={`/jour/${d.date}`} className="list-row pressable" style={{ minHeight: 56 }}>
                    <span className="icon-chip" style={{ width: 26, height: 26, color: '#fff', background: d.status === 'incomplete' ? 'var(--mc-danger)' : 'var(--mc-success)', fontWeight: 800 }}>
                      {d.status === 'incomplete' ? '!' : <Icon name="check" size={15} stroke={3} />}
                    </span>
                    <span className="grow stack" style={{ gap: 2 }}>
                      <span style={{ fontSize: 14, fontWeight: 700 }}>{d.label}</span>
                      <span className="list-row-sub">{d.summary}</span>
                      {d.missing && <span className="small" style={{ fontWeight: 600, color: 'var(--mc-danger)' }}>{d.missing}</span>}
                    </span>
                    <Icon name="chevR" size={18} />
                  </Link>
                ))}
              </StaggeredList>
            </div>

            {/* 3. Problème restant, puis 4. action */}
            {incomplete && (
              <Reveal delay={1400} className="banner banner--danger">
                <span className="row" style={{ fontWeight: 700, fontSize: 14 }}><Icon name="alert" size={18} />{incomplete.label} : {incomplete.missing?.toLowerCase()}</span>
                <Reveal delay={1600}>
                  <Link to={`/jour/${incomplete.date}`} className="btn btn--primary btn--block">Compléter maintenant</Link>
                </Reveal>
              </Reveal>
            )}
            </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function Tile({ tone, icon, value, label }: { tone: string; icon: 'pen' | 'hand' | 'stetho' | 'moon'; value: number; label: string }) {
  return (
    <div className={`tile tile--row tone-${tone}`}>
      <Icon name={icon} size={24} />
      <span className="stack" style={{ gap: 0 }}><span className="tile-value" style={{ fontSize: 20 }}>{value}</span><span className="tile-label" style={{ fontSize: 12 }}>{label}</span></span>
    </div>
  );
}
