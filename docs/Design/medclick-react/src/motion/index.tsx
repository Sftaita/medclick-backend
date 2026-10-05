// Primitives d'animation réutilisables (§37.26). Les animations ne touchent jamais à la logique métier :
// elles reçoivent la valeur réelle et se contentent de la mettre en scène.
import {
  Children, useEffect, useRef, useState, type CSSProperties, type ReactNode,
} from 'react';
import { DELAY, DURATION, PARTICLE_COUNT } from './tokens';
import { useTween } from './useTween';
import { useReducedMotion } from './useReducedMotion';
import { Icon, type IconName } from '@/components/Icon';

export { useReducedMotion } from './useReducedMotion';
export { useTween } from './useTween';

/* ---------------------------------------------------------------- AnimatedNumber */
export function AnimatedNumber({
  value, from = 0, duration = DURATION.counter, delay = 0, format = (n) => String(Math.round(n)), onDone, className,
}: {
  value: number; from?: number; duration?: number; delay?: number;
  format?: (n: number) => string; onDone?: () => void; className?: string;
}) {
  const v = useTween(value, { from, duration, delay, onDone });
  return <span className={'tabular ' + (className ?? '')} aria-label={format(value)}>{format(v)}</span>;
}

/* ---------------------------------------------------------- AnimatedProgressRing */
export function AnimatedProgressRing({
  value, from = 0, size = 44, stroke = 7, color = 'var(--mc-ring)', track = 'var(--mc-ring-track)',
  duration = DURATION.ring, delay = 0, pulseOnDone = false, onDone, children,
}: {
  value: number; from?: number; size?: number; stroke?: number; color?: string; track?: string;
  duration?: number; delay?: number; pulseOnDone?: boolean; onDone?: () => void;
  children?: (current: number) => ReactNode;
}) {
  const [pulse, setPulse] = useState(false);
  const v = useTween(value, {
    from, duration, delay,
    onDone: () => { if (pulseOnDone) setPulse(true); onDone?.(); },
  });
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  return (
    <span className={'ring ' + (pulse ? 'mc-pulse' : '')} style={{ width: size, height: size }} onAnimationEnd={() => setPulse(false)}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={track} strokeWidth={stroke} />
        <circle
          cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={`${(c * Math.min(100, v)) / 100} ${c}`} transform={`rotate(-90 ${size / 2} ${size / 2})`}
        />
      </svg>
      {children && <span className="ring-label">{children(v)}</span>}
    </span>
  );
}

/* ----------------------------------------------------------- AnimatedProgressBar */
export function AnimatedProgressBar({ value, color = 'var(--mc-primary)', delay = 0, height = 8 }: {
  value: number; color?: string; delay?: number; height?: number;
}) {
  const v = useTween(value, { duration: DURATION.bar, delay });
  return (
    <span className="bar" style={{ height }} role="progressbar" aria-valuenow={Math.round(value)} aria-valuemin={0} aria-valuemax={100}>
      <span className="bar-fill" style={{ transform: `scaleX(${Math.min(100, v) / 100})`, background: color }} />
    </span>
  );
}

/* ------------------------------------------------------------- Reveal / Stagger */
export function Reveal({ delay = 0, children, className = '', fadeOnly = false, as: Tag = 'div', style }: {
  delay?: number; children: ReactNode; className?: string; fadeOnly?: boolean;
  as?: 'div' | 'span' | 'li' | 'section'; style?: CSSProperties;
}) {
  return (
    <Tag className={`${fadeOnly ? 'mc-fade' : 'mc-enter'} ${className}`} style={{ animationDelay: `${delay}ms`, ...style }}>
      {children}
    </Tag>
  );
}

/** §37.2 / §37.11 — fait apparaître les enfants les uns après les autres (ensemble < 1 s). */
export function StaggeredList({ children, start = 0, step = DELAY.stagger, className = '', as = 'div' }: {
  children: ReactNode; start?: number; step?: number; className?: string; as?: 'div' | 'li';
}) {
  return (
    <>
      {Children.toArray(children).map((child, i) => (
        <Reveal key={i} delay={start + i * step} className={className} as={as}>{child}</Reveal>
      ))}
    </>
  );
}

/* --------------------------------------------------------------- SuccessFeedback */
export function SuccessFeedback({ title, subtitle, size = 76 }: { title: string; subtitle?: string; size?: number }) {
  return (
    <div className="success" role="status">
      <span className="success-halo mc-pop" style={{ width: size, height: size }}>
        <span className="success-dot" style={{ width: size * 0.7, height: size * 0.7 }}>
          <svg width={size * 0.38} height={size * 0.38} viewBox="0 0 24 24" aria-hidden="true">
            <path className="mc-draw" d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="#fff" strokeWidth={3} strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </span>
      </span>
      <Reveal delay={100}><h2 className="success-title">{title}</h2></Reveal>
      {subtitle && <Reveal delay={160} fadeOnly><p className="muted center">{subtitle}</p></Reveal>}
    </div>
  );
}

/* ------------------------------------------------------------- AchievementReveal */
export function AchievementReveal({ title, subtitle, onClose, action, floating = false, icon = 'trophy' }: {
  title: string; subtitle?: string; onClose?: () => void; action?: ReactNode; floating?: boolean; icon?: IconName;
}) {
  const reduced = useReducedMotion();
  const particles = reduced ? [] : Array.from({ length: PARTICLE_COUNT }, (_, i) => {
    const a = ((i * 360) / PARTICLE_COUNT + 12) * (Math.PI / 180);
    const d = 26 + (i % 3) * 5;
    return {
      '--dx': `${Math.cos(a) * d}px`, '--dy': `${Math.sin(a) * d}px`,
      background: ['#2F5BEA', '#1D9E6E', '#F0A12B', '#5FD6C8'][i % 4],
      borderRadius: i % 2 ? '50%' : '1px',
    } as CSSProperties;
  });
  return (
    <div className={'achievement mc-enter-up ' + (floating ? 'achievement--floating' : '')} role="status">
      <span className="achievement-badge">
        {particles.map((s, i) => <span key={i} className="particle" style={s} />)}
        <span className="achievement-icon mc-wiggle"><Icon name={icon} size={28} /></span>
      </span>
      <span className="achievement-text">
        <span className="eyebrow eyebrow--gold">Nouveau milestone</span>
        <strong>{title}</strong>
        {subtitle && <span className="muted small">{subtitle}</span>}
        {action}
      </span>
      {onClose && (
        <button type="button" className="icon-btn icon-btn--soft" aria-label="Fermer" onClick={onClose}>×</button>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ CountStepper */
export function CountStepper({ value, onChange, min = 1, max = 20, label }: {
  value: number; onChange: (v: number) => void; min?: number; max?: number; label: string;
}) {
  const [dir, setDir] = useState<'up' | 'down' | null>(null);
  const set = (v: number, d: 'up' | 'down') => { if (v < min || v > max) return; setDir(d); onChange(v); };
  return (
    <div className="stepper" role="group" aria-label={label}>
      <button type="button" className="stepper-btn stepper-btn--minus pressable" aria-label="Diminuer"
        disabled={value <= min} onClick={() => set(value - 1, 'down')}><Icon name="minus" size={20} stroke={2.6} /></button>
      <output key={value} aria-live="polite" className={'stepper-value tabular ' + (dir ? `mc-bump-${dir}` : '')}>{value}</output>
      <button type="button" className="stepper-btn stepper-btn--plus pressable" aria-label="Augmenter"
        disabled={value >= max} onClick={() => set(value + 1, 'up')}><Icon name="plus" size={20} stroke={2.6} /></button>
    </div>
  );
}

/* ------------------------------------------------------------------ FavoriteStar */
export function FavoriteStar({ active, onToggle, label }: { active: boolean; onToggle: () => void; label: string }) {
  const [pop, setPop] = useState(false);
  return (
    <button type="button" className={'icon-btn star ' + (active ? 'star--on' : '')} aria-pressed={active}
      aria-label={active ? `Retirer ${label} des favoris` : `Ajouter ${label} aux favoris`}
      onClick={() => { if (!active) setPop(true); onToggle(); }}>
      <span className={pop ? 'mc-star-pop' : ''} onAnimationEnd={() => setPop(false)}>
        <Icon name={active ? 'starFilled' : 'star'} size={22} />
      </span>
    </button>
  );
}

/* ------------------------------------------------------------------- Collapsible */
/** §37.19 — suppression neutre : la ligne se contracte puis disparaît. */
export function Collapsible({ removing, onRemoved, children }: { removing: boolean; onRemoved: () => void; children: ReactNode }) {
  const reduced = useReducedMotion();
  const done = useRef(false);
  useEffect(() => {
    if (removing && reduced && !done.current) { done.current = true; onRemoved(); }
  }, [removing, reduced, onRemoved]);
  return (
    <div className={'collapsible ' + (removing ? 'collapsible--out' : '')}
      onTransitionEnd={(e) => { if (removing && e.propertyName === 'grid-template-rows' && !done.current) { done.current = true; onRemoved(); } }}>
      <div className="collapsible-inner">{children}</div>
    </div>
  );
}
