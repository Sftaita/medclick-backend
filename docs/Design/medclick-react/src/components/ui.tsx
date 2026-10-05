import { useId, type ReactNode } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { Icon, type IconName } from './Icon';
import { Reveal } from '@/motion';
import { DELAY } from '@/motion/tokens';
import { usePartner } from '@/hooks/usePartner';
import type { Acte, Partner, Region, Role, Speciality } from '@/types';
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

/**
 * Logo du partenaire : seule source pour tous les emplacements. Lit le partenaire géré dans l'administration ;
 * ne rend rien s'il n'y en a pas (le conteneur doit se replier, voir `usePartner`).
 */
export function SponsorMark({ logoSize = 22, textSize = 20, preview }: {
  logoSize?: number; textSize?: number;
  /** Aperçu d'un partenaire non publié (administration) */
  preview?: Partner | null;
}) {
  const ctx = usePartner();
  const partner = preview !== undefined ? preview : ctx.partner;
  const id = useId();
  if (!partner) return null;
  return (
    <span className="row" style={{ gap: 6, fontWeight: 600, letterSpacing: -0.5, fontSize: textSize, lineHeight: 1.2 }}>
      {(partner.logoUrl
        ? <img src={partner.logoUrl} alt="" height={logoSize} style={{ height: logoSize, width: 'auto' }} />
        : (
          <svg width={logoSize} height={logoSize} viewBox="0 0 40 40" aria-hidden="true">
            <defs><linearGradient id={id} x1="0" y1="1" x2="1" y2="0"><stop offset="0" stopColor="#2447D6" /><stop offset="1" stopColor="#4FA8F5" /></linearGradient></defs>
            <path d="M7 35C4 21 13 8 33 3c2 16-8 29-26 32z" fill={`url(#${id})`} />
            <path d="M8 34c5-10 12-18 21-24" stroke="#fff" strokeWidth="2" fill="none" strokeLinecap="round" />
          </svg>
        ))}
      <span>{partner.name}</span>
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

const TABS: { to: string; label: string; icon: IconName; end?: boolean }[] = [
  { to: '/', label: 'Accueil', icon: 'home', end: true },
  { to: '/activites', label: 'Activités', icon: 'pen' },
  { to: '/semaine', label: 'Ma semaine', icon: 'calendar' },
  { to: '/progression', label: 'Progression', icon: 'chart' },
  { to: '/profil', label: 'Plus', icon: 'dots' },
];

export function TabBar() {
  return (
    <nav className="tabbar" aria-label="Navigation principale">
      {TABS.map((t) => (
        <NavLink key={t.to} to={t.to} end={t.end}>
          <Icon name={t.icon} size={22} />{t.label}
        </NavLink>
      ))}
    </nav>
  );
}

/** Sous-navigation des listes d'activités. */
export function ActivityTabs() {
  return (
    <nav className="tabs tabs--4" aria-label="Type d'activité">
      <NavLink to="/activites" end>Interventions</NavLink>
      <NavLink to="/activites/consultations">Consultations</NavLink>
      <NavLink to="/activites/gardes">Gardes</NavLink>
      <NavLink to="/activites/formations">Formations</NavLink>
    </nav>
  );
}

export function Skeleton({ h = 16, w = '100%', r }: { h?: number; w?: number | string; r?: number }) {
  return <span className="skeleton" style={{ display: 'block', height: h, width: w, borderRadius: r }} aria-hidden="true" />;
}

/* ------------------------------------------------------------------ Métier */
export const ROLE_COLOR: Record<Role, string> = {
  SOLO: 'var(--mc-role-solo)',
  ASSISTED: 'var(--mc-role-assisted)',
  SECOND: 'var(--mc-role-second)',
};

export const RoleDot = ({ role }: { role: Role }) => <span className="dot" style={{ background: ROLE_COLOR[role] }} />;

const REGION_ICON: Record<Region, IconName> = {
  shoulder: 'shoulder', humerus: 'longBone', elbow: 'elbow', forearm: 'forearm', wristhand: 'wristhand', back: 'back',
  pelvic: 'pelvic', hip: 'hip', proximalFemur: 'longBone', midFemur: 'longBone', distalFemur: 'longBone', knee: 'knee',
  limb: 'limb', ankle: 'ankle', foot: 'foot',
};
const SPECIALITY_ICON: Record<Speciality, IconName> = {
  ortho: 'longBone', dig: 'dig', general: 'general', uro: 'uro', vasc: 'vasc', thor: 'thor', plastic: 'plastic', neuro: 'neuro', transp: 'transp',
};

/** Icône d'un acte : la région anatomique en orthopédie, sinon la spécialité, sinon « générale ». */
export const acteIcon = (a?: Pick<Acte, 'region' | 'speciality'>): IconName =>
  (a?.region && REGION_ICON[a.region]) || (a?.speciality && SPECIALITY_ICON[a.speciality]) || 'general';

export const ActeIcon = ({ acte, size = 20 }: { acte?: Pick<Acte, 'region' | 'speciality'>; size?: number }) => (
  <span className={'icon-chip icon-chip--sq ' + (acte && acte.speciality !== 'ortho' ? 'tone-purple' : '')}><Icon name={acteIcon(acte)} size={size} /></span>
);

/** Code INAMI, ou emplacement réservé s'il n'est pas connu. */
export const CodeBadge = ({ code }: { code?: string }) => <span className="code">{code ?? '[code INAMI]'}</span>;

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
      {onRetry && <button type="button" className="btn btn--secondary btn--sm" style={{ alignSelf: 'flex-start' }} onClick={onRetry}>Réessayer</button>}
    </div>
  );
}

/** Case à cocher au style MedClick (vraie `<input type="checkbox">`). */
export function Checkbox({ checked, onChange, children }: { checked: boolean; onChange: (v: boolean) => void; children: ReactNode }) {
  return (
    <label className="check">
      <input type="checkbox" className="sr-only" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span className={'check-box ' + (checked ? 'check-box--on' : '')} aria-hidden="true">{checked && <Icon name="check" size={14} stroke={3} />}</span>
      <span>{children}</span>
    </label>
  );
}

/** Interrupteur (vraie case à cocher). */
export function Switch({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <span className="switch">
      <input type="checkbox" className="sr-only" aria-label={label} checked={checked} onChange={(e) => onChange(e.target.checked)} />
      <span className={'switch-track ' + (checked ? 'switch-track--on' : '')} aria-hidden="true" />
    </span>
  );
}
