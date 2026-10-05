import { Link, useNavigate } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { usePartner } from '@/hooks/usePartner';
import { Icon, type IconName } from '@/components/Icon';
import { Skeleton } from '@/components/ui';
import { StaggeredList } from '@/motion';
import { SPECIALITY_LABEL, YEAR_LABEL } from '@/data/referentiel';

const MENU: { label: string; icon: IconName; to: string; accent?: boolean; badge?: 'terms' }[] = [
  { label: 'Mes informations', icon: 'user', to: '/profil/informations' },
  { label: 'Mes années de formation', icon: 'cap', to: '/annees' },
  { label: 'Mes chirurgiens', icon: 'users', to: '/chirurgiens' },
  { label: 'Mes favoris', icon: 'star', to: '/favoris' },
  { label: 'Mes formations et congrès', icon: 'book', to: '/activites/formations' },
  { label: 'Exporter mon carnet (Excel)', icon: 'doc', to: '/annees', accent: true },
  { label: 'Aide et contact', icon: 'help', to: '/aide' },
  { label: 'À propos de MedClick', icon: 'info', to: '/a-propos' },
  { label: "Conditions d'utilisation", icon: 'doc', to: '/cgu', badge: 'terms' },
  { label: 'Politique de confidentialité', icon: 'shield', to: '/confidentialite' },
];

export default function ProfilePage() {
  const nav = useNavigate();
  const { partner } = usePartner();
  const { data: u } = useAsync(() => api.getProfile(), []);
  const logout = async () => { await api.logout(); nav('/connexion', { replace: true }); };
  return (
    <div className="screen">
      <header className="topbar">
        <span className="topbar-spacer" />
        <h1 className="topbar-title topbar-title--center">Mon profil</h1>
        <Link to="/profil/informations" className="btn btn--ghost btn--sm">Modifier</Link>
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
        {u?.currentYear && (
          <Link to="/annees" className="card card--outline row" style={{ flexDirection: 'row', gap: 14, textDecoration: 'none', color: 'var(--mc-ink)' }}>
            <span className="icon-chip icon-chip--sq" style={{ width: 40, height: 40 }}><Icon name="building" size={22} /></span>
            <span className="stack grow" style={{ gap: 2 }}>
              <strong style={{ fontSize: 14 }}>{YEAR_LABEL(u.currentYear.yearOfFormation)} de formation · {SPECIALITY_LABEL[u.speciality].replace('Chirurgie ', '')}</strong>
              <span className="muted small">{u.currentYear.hospital} · maître de stage : {u.currentYear.master}</span>
            </span>
          </Link>
        )}
        </div>
        <div className="split-side">
        <nav className="list list-card" aria-label="Paramètres">
          <StaggeredList step={35}>
            {MENU.map((m) => (
              <Link key={m.label} to={m.to} className="list-row" style={{ minHeight: 52 }}>
                <span style={{ color: m.accent ? 'var(--mc-primary)' : 'var(--mc-muted)', display: 'flex' }}><Icon name={m.icon} size={20} /></span>
                <span className="grow" style={{ fontSize: 14, fontWeight: 600, color: m.accent ? 'var(--mc-primary)' : undefined }}>{m.label}</span>
                {m.badge === 'terms' && u?.termsAccepted && <span className="row small" style={{ gap: 6, color: 'var(--mc-success)', fontWeight: 700 }}><Icon name="check" size={14} stroke={3} />Acceptées</span>}
                <Icon name="chevR" size={18} />
              </Link>
            ))}
          </StaggeredList>
        </nav>
        </div>
        </div>
        {/* Sans partenaire, le lien disparaît. */}
        {partner && <Link to="/partenaires" className="btn btn--ghost" style={{ color: 'var(--mc-muted)', fontSize: 13 }}>Avec le soutien de nos partenaires ›</Link>}
        <button type="button" className="btn btn--danger-ghost" onClick={logout}>Se déconnecter</button>
      </div>
    </div>
  );
}
