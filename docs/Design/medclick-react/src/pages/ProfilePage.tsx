import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon, type IconName } from '@/components/Icon';
import { Skeleton } from '@/components/ui';
import { StaggeredList } from '@/motion';
import { yearLabel } from '@/lib/format';

const MENU: { label: string; icon: IconName; to: string; sync?: boolean }[] = [
  { label: 'Mes informations', icon: 'user', to: '#' },
  { label: 'Préférences', icon: 'gear', to: '#' },
  { label: 'Notifications', icon: 'bell', to: '#' },
  { label: 'Synchronisation', icon: 'sync', to: '#', sync: true },
  { label: 'Aide et contact', icon: 'help', to: '#' },
  { label: 'À propos de MedClick', icon: 'info', to: '#' },
  { label: "Conditions d'utilisation", icon: 'doc', to: '#' },
  { label: 'Politique de confidentialité', icon: 'shield', to: '#' },
];

export default function ProfilePage() {
  const { data: u } = useAsync(() => api.getProfile(), []);
  return (
    <div className="screen">
      <header className="topbar">
        <span className="topbar-spacer" />
        <h1 className="topbar-title topbar-title--center">Mon profil</h1>
        <button type="button" className="btn btn--ghost btn--sm">Modifier</button>
      </header>
      <div className="screen-body screen-body--tabbed" style={{ gap: 14 }}>
        <div className="split">
        <div className="split-main stack" style={{ gap: 14 }}>
        {!u ? <Skeleton h={72} r={16} /> : (
          <div className="row" style={{ gap: 16 }}>
            <span className="avatar avatar--lg">{u.firstName[0]}</span>
            <span className="stack" style={{ gap: 2 }}>
              <strong style={{ fontSize: 20 }}>{u.firstName} {u.lastName}</strong>
              <span className="muted" style={{ fontSize: 13 }}>{u.email}</span>
            </span>
          </div>
        )}
        {u && (
          <div className="card card--outline row" style={{ flexDirection: 'row', gap: 14 }}>
            <span className="icon-chip icon-chip--sq" style={{ width: 40, height: 40 }}><Icon name="building" size={22} /></span>
            <span className="stack" style={{ gap: 2 }}>
              <strong style={{ fontSize: 14 }}>{yearLabel(u.trainingYear)} de formation</strong>
              <span className="muted small">{u.hospital}</span>
            </span>
          </div>
        )}
        </div>
        <div className="split-side">
        <nav className="list list-card" aria-label="Paramètres">
          <StaggeredList step={35}>
            {MENU.map((m) => (
              <Link key={m.label} to={m.to} className="list-row" style={{ minHeight: 52 }}>
                <span style={{ color: 'var(--mc-muted)', display: 'flex' }}><Icon name={m.icon} size={20} /></span>
                <span className="grow" style={{ fontSize: 14, fontWeight: 600 }}>{m.label}</span>
                {m.sync && <span className="row small" style={{ gap: 6, color: 'var(--mc-success)', fontWeight: 700 }}><Icon name="check" size={14} stroke={3} />À jour</span>}
                <Icon name="chevR" size={18} />
              </Link>
            ))}
          </StaggeredList>
        </nav>
        </div>
        </div>
        <Link to="/partenaires" className="btn btn--ghost" style={{ color: 'var(--mc-muted)', fontSize: 13 }}>Avec le soutien de nos partenaires ›</Link>
        <Link to="/connexion" className="btn btn--danger-ghost">Se déconnecter</Link>
      </div>
    </div>
  );
}
