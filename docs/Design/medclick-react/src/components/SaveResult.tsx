import { useEffect, useState, type ReactNode } from 'react';
import { Icon } from './Icon';
import { api } from '@/api';
import { AchievementReveal, AnimatedNumber, Reveal, SuccessFeedback } from '@/motion';
import { DELAY, DURATION } from '@/motion/tokens';
import type { BatchResponse } from '@/types';

/**
 * Écran de confirmation après un enregistrement RÉUSSI côté backend (§37.8, §37.9, §37.25).
 * 1. ✓ titre  2. impact sur l'année (avant → après)  3. milestone éventuel, après une petite pause.
 * Les boutons sont utilisables immédiatement : l'animation ne bloque jamais (§37.29).
 */
export function SaveResult({ result, title, actions }: { result: BatchResponse; title: string; actions: ReactNode }) {
  const { before, after, newlyAchieved } = result;
  const [showMs, setShowMs] = useState(false);
  const countersStart = 450;
  const fhChanged = after.firstHand !== before.firstHand;

  useEffect(() => {
    if (!newlyAchieved.length) return;
    const id = setTimeout(() => {
      setShowMs(true);
      api.markMilestonesCelebrated(newlyAchieved.map((m) => m.id)).catch(() => undefined);
    }, countersStart + DURATION.counter + DELAY.beforeMilestone);
    return () => clearTimeout(id);
  }, [newlyAchieved]);

  return (
    <div className="screen">
      <div className="screen-body narrow" style={{ alignItems: 'center', paddingTop: 24, maxWidth: 600 }}>
        <SuccessFeedback title={title} />

        <Reveal delay={350} className="card card--outline" style={{ width: '100%', background: '#FAFCFF' }}>
          <span className="eyebrow">Votre activité cette année</span>
          <ImpactRow icon="pen" label="Interventions" from={before.interventions} to={after.interventions} delay={countersStart} />
          {fhChanged && <ImpactRow icon="hand" label="Première main" from={before.firstHand} to={after.firstHand} delay={countersStart + 50} />}
        </Reveal>

        {showMs && newlyAchieved.map((m) => (
          <AchievementReveal key={m.id} title={m.title} subtitle={m.subtitle} />
        ))}

        <Reveal fadeOnly delay={350}>
          <p className="muted small center" style={{ lineHeight: 1.45 }}>
            Chaque intervention est enregistrée séparément et reste modifiable individuellement.
          </p>
        </Reveal>
      </div>
      <div className="bottom-action">{actions}</div>
    </div>
  );
}

function ImpactRow({ icon, label, from, to, delay }: { icon: 'pen' | 'hand'; label: string; from: number; to: number; delay: number }) {
  return (
    <div className="row">
      <span style={{ color: icon === 'pen' ? 'var(--mc-primary)' : '#13865A', display: 'flex' }}><Icon name={icon} size={22} /></span>
      <span className="grow" style={{ fontSize: 14, color: 'var(--mc-ink-2)' }}>{label}</span>
      <span className="kpi-value"><AnimatedNumber value={to} from={from} delay={delay} duration={700} /></span>
      <Reveal as="span" delay={delay + 700 + DELAY.deltaAfterValue} className="delta">+{to - from}</Reveal>
    </div>
  );
}
