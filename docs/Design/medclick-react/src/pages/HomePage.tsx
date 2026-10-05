import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { DecoCross, DeltaBadge, ErrorState, Logo, Skeleton, SponsorMark, Wordmark } from '@/components/ui';
import { AchievementReveal, AnimatedNumber, AnimatedProgressRing, Reveal, StaggeredList } from '@/motion';
import { DELAY, DURATION } from '@/motion/tokens';
import { YEAR_LABEL } from '@/data/referentiel';
import { usePartner } from '@/hooks/usePartner';
import type { Milestone } from '@/types';

/* Séquence d'accueil (§37.24) :
   skeleton → compteurs principaux (interventions, 1re main) → anneau 72 % + impulsion → évolutions
   → série de semaines → (milestone éventuel ~300 ms plus tard). Le reste apparaît par simple fondu (§37.22). */
const T = { card: 0, int: 60, fh: 120, ring: 140, week: 450, streak: 900, partner: 950 };

export default function HomePage() {
  const { data, error, reload } = useAsync(() => api.getDashboard(), []);
  const { partner } = usePartner();
  const [celebrate, setCelebrate] = useState<Milestone | null>(null);
  const [flame, setFlame] = useState(false);

  // Milestones atteints depuis la dernière visite : montrés une seule fois puis marqués côté serveur (§37.6).
  useEffect(() => {
    const m = data?.pendingCelebrations[0];
    if (!m) return;
    const id = setTimeout(() => {
      setCelebrate(m);
      api.markMilestonesCelebrated(data!.pendingCelebrations.map((x) => x.id)).catch(() => undefined);
    }, T.ring + DURATION.ring + DURATION.pulse + DELAY.beforeMilestone);
    return () => clearTimeout(id);
  }, [data]);

  // Série augmentée depuis la dernière visite : le feu s'anime une fois (§37.13).
  const streakUp = !!data && data.streak.weeks > data.streak.acknowledgedWeeks;
  useEffect(() => {
    if (!streakUp || !data) return;
    const id = setTimeout(() => setFlame(true), T.streak + 100);
    api.acknowledgeStreak(data.streak.weeks).catch(() => undefined);
    return () => clearTimeout(id);
  }, [streakUp, data]);

  const y = data?.year;

  return (
    <div className="screen" style={{ position: 'relative' }}>
      <DecoCross size={150} style={{ right: -36, top: 70, opacity: 0.18 }} />
      <div className="screen-body screen-body--tabbed screen-body--fab" style={{ gap: 14, paddingTop: 12 }}>
        <header className="row" style={{ gap: 8 }}>
          <span className="only-mobile" style={{ display: 'flex' }}><Logo size={28} /></span>
          <span className="grow"><span className="only-mobile"><Wordmark /></span></span>
          <button type="button" className="icon-btn" aria-label="Notifications"><Icon name="bell" size={22} /><span className="badge-dot" /></button>
          <Link to="/profil" className="avatar" aria-label="Mon profil">{data?.user.firstName[0] ?? ''}</Link>
        </header>

        <div>
          <h1 className="h1">Bonsoir {data?.user.firstName ?? ''}</h1>
          <p className="muted" style={{ fontSize: 14 }}>Belle progression cette année !</p>
        </div>

        {error && <ErrorState error={error} onRetry={reload} />}

        <div className="split">
        <div className="split-main">
        {!data || !y ? (
          <div className="card" aria-busy="true" aria-label="Chargement">
            <Skeleton w={150} h={14} />
            <div className="kpi-grid">{[0, 1, 2, 3].map((i) => <Skeleton key={i} h={92} r={16} />)}</div>
            <Skeleton h={44} r={14} />
          </div>
        ) : (
          <Reveal className="card" delay={T.card}>
            <span className="section-title" style={{ fontSize: 14 }}>
              <span style={{ color: 'var(--mc-primary)', display: 'flex' }}><Icon name="chart" size={18} stroke={2.6} /></span>
              Mon année{data.user.currentYear ? ` (${YEAR_LABEL(data.user.currentYear.yearOfFormation)} · ${data.user.currentYear.hospital})` : ''}
            </span>
            <div className="kpi-grid">
              <Kpi icon="pen" tone="blue" label="interventions cette année">
                <AnimatedNumber value={y.interventions} delay={T.int} />
                <DeltaBadge value={y.deltas.interventions} delay={T.int + DURATION.counter} style={{ marginLeft: 50 }} />
              </Kpi>
              <Kpi icon="hand" tone="green" label="en première main">
                <AnimatedNumber value={y.firstHand} delay={T.fh} />
                <DeltaBadge value={y.deltas.firstHand} delay={T.fh + DURATION.counter} style={{ marginLeft: 50 }} />
              </Kpi>
              <Reveal fadeOnly delay={200} className="kpi">
                <div className="row">
                  <span className="icon-chip tone-purple" style={{ width: 40, height: 40 }}><Icon name="stetho" size={20} /></span>
                  <span className="stack" style={{ gap: 0 }}><span className="kpi-value">{y.consultations}</span><span className="kpi-label">consultations</span></span>
                </div>
                <DeltaBadge value={y.deltas.consultations} delay={400} style={{ marginLeft: 50 }} />
              </Reveal>
              <div className="kpi">
                <div className="row">
                  <AnimatedProgressRing value={y.completion} delay={T.ring} pulseOnDone={y.completion >= 70} />
                  <span className="stack" style={{ gap: 0 }}>
                    <span className="kpi-value"><AnimatedNumber value={y.completion} delay={T.ring} duration={DURATION.ring} /> %</span>
                    <span className="kpi-label">du carnet complété</span>
                  </span>
                </div>
                <DeltaBadge value={y.deltas.completionThisMonth} delay={T.ring + DURATION.ring + DURATION.pulse - DELAY.deltaAfterValue + 100} style={{ marginLeft: 54 }} />
              </div>
            </div>
            <Reveal delay={T.streak}>
              <Link to="/semaine" className="streak">
                <span className={'streak-flame ' + (flame ? 'mc-flame' : '')}><Icon name="flame" size={20} /></span>
                <span className="grow">
                  {streakUp
                    ? <AnimatedNumber value={data.streak.weeks} from={data.streak.acknowledgedWeeks} delay={T.streak + 100} duration={300} />
                    : data.streak.weeks}{' '}semaines à jour
                </span>
                <Icon name="chevR" size={18} />
              </Link>
            </Reveal>
          </Reveal>
        )}
        </div>

        <div className="split-side">
        {data && (
          <Reveal fadeOnly delay={T.week} className="stack">
            <div className="row">
              <span className="section-title grow"><span style={{ color: 'var(--mc-primary)', display: 'flex' }}><Icon name="calendar" size={20} /></span>Cette semaine</span>
              <Link to="/semaine" style={{ fontSize: 13, fontWeight: 600, textDecoration: 'none' }}>Voir tout ›</Link>
            </div>
            <div className="grid-3">
              <StaggeredList start={T.week} step={60}>
                <WeekTile tone="blue" icon="pen" value={data.week.interventions} label="interventions encodées" />
                <WeekTile tone="green" icon="hand" value={data.week.firstHand} label="première main" />
                <WeekTile tone="red" icon="calendar" value={data.week.incompleteDays} label={data.week.incompleteDays > 1 ? 'jours sans activité' : 'jour sans activité'} />
              </StaggeredList>
            </div>
            <Link to="/semaine" className="btn btn--primary pressable">Compléter ma semaine <Icon name="arrowR" size={18} stroke={2.4} /></Link>
          </Reveal>
        )}

        {data && partner && (
          // §37.20 — sponsor : simple fondu, jamais répété. Sans partenaire, la carte disparaît.
          <Reveal fadeOnly delay={T.partner}>
            <Link to="/partenaires" className="partner-card pressable">
              <span className="stack grow" style={{ gap: 4, padding: '12px 14px' }}>
                <span className="small muted" style={{ fontWeight: 600 }}>Partenaire du mois</span>
                <SponsorMark logoSize={22} textSize={20} />
                <span className="small muted">{partner.tagline}</span>
              </span>
              <span className="partner-visual" />
            </Link>
          </Reveal>
        )}
        </div>
        </div>
      </div>

      <Link to="/ajouter" className="fab fab--float" aria-label="Encoder une activité"><Icon name="plus" size={26} stroke={2.6} /></Link>

      {celebrate && (
        <AchievementReveal
          floating title={celebrate.title} subtitle={celebrate.subtitle}
          action={<Link to="/progression/milestones" className="small" style={{ fontWeight: 600 }}>Voir mes milestones</Link>}
          onClose={() => setCelebrate(null)}
        />
      )}
    </div>
  );
}

function Kpi({ icon, tone, label, children }: { icon: 'pen' | 'hand'; tone: 'blue' | 'green'; label: string; children: [React.ReactNode, React.ReactNode] }) {
  return (
    <div className="kpi">
      <div className="row">
        <span className={`icon-chip tone-${tone}`} style={{ width: 40, height: 40 }}><Icon name={icon} size={20} /></span>
        <span className="stack" style={{ gap: 0 }}>
          <span className="kpi-value">{children[0]}</span>
          <span className="kpi-label">{label}</span>
        </span>
      </div>
      {children[1]}
    </div>
  );
}

function WeekTile({ tone, icon, value, label }: { tone: string; icon: 'pen' | 'hand' | 'calendar'; value: number; label: string }) {
  return (
    <div className={`tile tone-${tone}`}>
      <Icon name={icon} size={22} />
      <span className="tile-value">{value}</span>
      <span className="tile-label">{label}</span>
    </div>
  );
}
