import { Link, NavLink, Outlet, useLocation, useNavigationType } from 'react-router-dom';
import { Icon, type IconName } from '@/components/Icon';
import { Logo, PartnerMark, TabBar, Wordmark } from '@/components/ui';

const NAV: { to: string; label: string; icon: IconName; end?: boolean }[] = [
  { to: '/', label: 'Accueil', icon: 'home', end: true },
  { to: '/interventions', label: 'Interventions', icon: 'pen' },
  { to: '/semaine', label: 'Ma semaine', icon: 'calendar' },
  { to: '/progression', label: 'Progression', icon: 'chart' },
  { to: '/profil', label: 'Profil', icon: 'user' },
];

/**
 * Coque responsive :
 *  - mobile (< 768 px) : barre d'onglets en bas ;
 *  - tablette (768–1199 px) : rail de navigation à gauche (icônes + libellés courts) ;
 *  - ordinateur (≥ 1200 px) : barre latérale complète avec marque, bouton d'ajout et partenaire.
 * Transition discrète entre écrans (§37.16).
 */
export function AppLayout({ tabs = true }: { tabs?: boolean }) {
  const location = useLocation();
  const navType = useNavigationType();
  const cls = navType === 'POP' ? 'page--back' : 'page--forward';
  return (
    <div className="app-shell">
      <Sidebar />
      <main className="app-main">
        <div key={location.pathname} className={'page ' + cls}>
          <Outlet />
        </div>
      </main>
      {tabs && <TabBar />}
    </div>
  );
}

function Sidebar() {
  return (
    <aside className="sidebar" aria-label="Navigation principale">
      <Link to="/" className="sidebar-brand" aria-label="MedClick — accueil">
        <Logo size={34} />
        <span className="sidebar-wordmark"><Wordmark size={21} /></span>
      </Link>
      <Link to="/interventions/ajouter" className="sidebar-cta" aria-label="Ajouter une intervention">
        <Icon name="plus" size={20} stroke={2.6} />
        <span className="sidebar-label">Ajouter</span>
      </Link>
      <nav className="sidebar-nav">
        {NAV.map((n) => (
          <NavLink key={n.to} to={n.to} end={n.end} className="sidebar-link">
            <Icon name={n.icon} size={22} />
            <span className="sidebar-label">{n.label}</span>
          </NavLink>
        ))}
      </nav>
      <Link to="/partenaires" className="sidebar-partner">
        <span className="small muted" style={{ fontWeight: 600 }}>Avec le soutien de</span>
        <span style={{ fontSize: 17 }}><PartnerMark size={20} /></span>
      </Link>
    </aside>
  );
}
