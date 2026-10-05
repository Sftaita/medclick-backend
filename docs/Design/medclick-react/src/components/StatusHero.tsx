import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';

const TONES = {
  blue: { halo: 'var(--mc-primary-soft)', dot: 'var(--mc-gradient)' },
  green: { halo: 'var(--mc-success-soft)', dot: 'var(--mc-success)' },
  orange: { halo: 'var(--mc-warn-soft)', dot: 'var(--mc-warn)' },
} as const;

/** Écran d'état des parcours de compte (e-mail envoyé, mot de passe modifié, lien expiré…). */
export function StatusHero({ icon, tone, title, children }: { icon: IconName; tone: keyof typeof TONES; title: string; children: ReactNode }) {
  return (
    <div className="account-hero" role="status">
      <span className="success-halo mc-pop" style={{ width: 96, height: 96, background: TONES[tone].halo }}>
        <span className="success-dot" style={{ width: 66, height: 66, background: TONES[tone].dot, color: '#fff' }}>
          <Icon name={icon} size={30} stroke={2.4} />
        </span>
      </span>
      <h1 className="h1" style={{ fontSize: 28 }}>{title}</h1>
      {children}
    </div>
  );
}

export function InfoCard({ children }: { children: ReactNode }) {
  return (
    <div className="card card--tint" style={{ width: '100%', textAlign: 'left', flexDirection: 'row', alignItems: 'flex-start', gap: 12 }}>
      <span style={{ color: 'var(--mc-primary)', display: 'flex' }}><Icon name="info" size={20} /></span>
      <span style={{ fontSize: 13, color: 'var(--mc-ink-2)', lineHeight: 1.45 }}>{children}</span>
    </div>
  );
}
