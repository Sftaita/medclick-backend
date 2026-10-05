import { useId, type ReactNode } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { Icon, type IconName } from './Icon';
import { Reveal } from '@/motion';
import { DELAY } from '@/motion/tokens';
import type { Acte, Role } from '@/types';
import { formatDelta } from '@/lib/format';

/* ------------------------------------------------------------------ Marque */
export function Logo({ size = 64 }: { size?: number }) {
  const id = useId();
  return (
    <svg width={size} height={size} viewBox="0 0 64 64" aria-hidden="true">
      <defs>
        <linearGradient id={`${id}v`} x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#5B8CFF" /><stop offset="1" stopColor="#2447D6" /></linearGradient>
        <linearGradient id={`${id}h`} x1="0" y1="0" x2="1" y2="0"><stop offset="0" stopColor="#5FD6C8" /><stop offset="1" stopColor="#3E7BF2" /></linearGradient>
      </defs>
      <rect x="22" y="3" width="20" height="58" rx="7" fill={`url(#${id}v)`} opacity="0.92" />
      <rect x="3" y="22" width="58" height="20" rx="7" fill={`url(#${id}h)`} opacity="0.82" />
      <rect x="22" y="22" width="20" height="20" fill="#1E3FC4" opacity="0.55" />
      <rect x="25" y="6" width="5" height="14" rx="2.5" fill="#fff" opacity="0.35" />
    </svg>
  );
}

export function Wordmark({ size = 19 }: { size?: number }) {
  return (
    <span style={{ fontSize: size, fontWeight: 800, letterSpacing: -size * 0.03, color: 'var(--mc-wordmark-a)' }}>
      Med<span style={{ color: 'var(--mc-wordmark-b)' }}>Click</span>
    </span>
  );
}

/** Logo partenaire (à remplacer par le fichier officiel fourni par le sponsor). */
export function PartnerMark({ size = 22, name = 'OrthoNova' }: { size?: number; name?: string }) {
  const id = useId();
  const split = name.startsWith('Ortho') ? ['Ortho', name.slice(5)] : [name, ''];
  return (
    <span className="row" style={{ gap: 6, fontWeight: 600, letterSpacing: -0.5 }}>
      <svg width={size} height={size} viewBox="0 0 40 40" aria-hidden="true">
        <defs><linearGradient id={id} x1="0" y1="1" x2="1" y2="0"><stop offset="0" stopColor="#2447D6" /><stop offset="1" stopColor="#4FA8F5" /></linearGradient></defs>
        <path d="M7 35C4 21 13 8 33 3c2 16-8 29-26 32z" fill={`url(#${id})`} />
        <path d="M8 34c5-10 12-18 21-24" stroke="#fff" strokeWidth="2" fill="none" strokeLinecap="round" />
      </svg>
      <span>{split[0]}<span style={{ color: 'var(--mc-primary)' }}>{split[1]}</span></span>
    </span>
  );
}

export function DecoCross({ size, style }: { size: number; style: React.CSSProperties }) {
  return (
    <svg className="deco-cross" width={size} height={size} viewBox="0 0 64 64" aria-hidden="true" style={style}>
      <rect x="22" y="3" width="20" height="58" rx="7" fill="#7FA2FF" />
      <rect x="3" y="22" width="58" height="20" rx="7" fill="#9FC0FF" />
    </svg>
  );
}

/* --------------------------------------------------------------- Structure */
export function TopBar({ title, back = true, center = false, right }: {
  title: string; back?: boolean | string; center?: boolean; right?: ReactNode;
}) {
  const nav = useNavigate();
  return (
    <header className="topbar">
      {back ? (
        typeof back === 'string'
          ? <Link to={back} className="icon-btn" aria-label="Retour"><Icon name="chevL" size={22} stroke={2.2} /></Link>
          : <button type="button" className="icon-btn" aria-label="Retour" onClick={() => nav(-1)}><Icon name="chevL" size={22} stroke={2.2} /></button>
      ) : <span className="topbar-spacer" />}
      <h1 className={'topbar-title ' + (center ? 'topbar-title--center' : '')}>{title}</h1>
      {right ?? (center ? <span className="topbar-spacer" /> : null)}
    </header>
  );
}

const TABS: { to: string; label: string; icon: IconName }[] = [
  { to: '/', label: 'Accueil', icon: 'home' },
  { to: '/interventions', label: 'Interventions', icon: 'pen' },
  { to: '/semaine', label: 'Ma semaine', icon: 'calendar' },
  { to: '/progression', label: 'Progression', icon: 'chart' },
  { to: '/profil', label: 'Plus', icon: 'dots' },
];

export function TabBar() {
  return (
    <nav className="tabbar" aria-label="Navigation principale">
      {TABS.map((t) => (
        <NavLink key={t.to} to={t.to} end={t.to === '/'}>
          <Icon name={t.icon} size={22} />{t.label}
        </NavLink>
      ))}
    </nav>
  );
}

export function Skeleton({ h = 16, w = '100%', r }: { h?: number; w?: number | string; r?: number }) {
  return <span className="skeleton" style={{ display: 'block', height: h, width: w, borderRadius: r }} aria-hidden="true" />;
}

/* ------------------------------------------------------------------ Métier */
export const ROLE_COLOR: Record<Role, string> = {
  FIRST_HAND: 'var(--mc-role-first)',
  ASSISTANT: 'var(--mc-role-assist)',
  OBSERVER: 'var(--mc-role-observe)',
};

export const RoleDot = ({ role }: { role: Role }) => <span className="dot" style={{ background: ROLE_COLOR[role] }} />;

export const ActeIcon = ({ acte, size = 20 }: { acte?: Acte; size?: number }) => (
  <span className="icon-chip icon-chip--sq"><Icon name={acte?.icon ?? 'joint'} size={size} /></span>
);

/** §37.15 — l'évolution arrive après le chiffre principal ; rien n'est affiché si elle n'est pas calculable. */
export function DeltaBadge({ value, delay = 0, style }: { value: number | null; delay?: number; style?: React.CSSProperties }) {
  const label = formatDelta(value);
  if (label === null) return null;
  return (
    <Reveal as="span" delay={delay + DELAY.deltaAfterValue} className={'delta ' + (value === 0 ? 'delta--neutral' : '')} style={style}>
      {value === 0 ? '= stable' : `${value! > 0 ? '↗' : '↘'} ${label}`}
    </Reveal>
  );
}

export function ErrorState({ error, onRetry }: { error: Error; onRetry?: () => void }) {
  return (
    <div className="banner banner--danger" role="alert">
      <span className="row"><Icon name="alert" size={18} />{error.message || 'Une erreur est survenue.'}</span>
      {onRetry && <button type="button" className="btn btn--secondary btn--sm" onClick={onRetry}>Réessayer</button>}
    </div>
  );
}
