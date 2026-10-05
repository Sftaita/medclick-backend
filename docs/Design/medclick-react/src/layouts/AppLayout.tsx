import { useCallback, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate, useNavigationType } from 'react-router-dom';
import { Icon, type IconName } from '@/components/Icon';
import { Logo, SponsorMark, TabBar, Wordmark } from '@/components/ui';
import { TermsGate } from '@/components/TermsGate';
import { CampaignInterstitial } from '@/components/CampaignInterstitial';
import { usePartner } from '@/hooks/usePartner';

const NAV: { to: string; label: string; icon: IconName; end?: boolean }[] = [
  { to: '/', label: 'Accueil', icon: 'home', end: true },
  { to: '/activites', label: 'Activités', icon: 'pen' },
  { to: '/semaine', label: 'Ma semaine', icon: 'calendar' },
  { to: '/progression', label: 'Progression', icon: 'chart' },
  { to: '/annees', label: 'Exporter le carnet', icon: 'doc' },
  { to: '/profil', label: 'Profil', icon: 'user' },
];

/**
 * Coque responsive :
 *  - mobile (< 768 px) : barre d'onglets en bas ;
 *  - tablette (768–1199 px) : rail de navigation à gauche (icônes + libellés courts) ;
 *  - ordinateur (≥ 1200 px) : barre latérale complète avec marque, bouton d'ajout et partenaire.
 * Affiche aussi la campagne plein écran après la connexion et la validation des CGU.
 */
export function AppLayout({ tabs = true }: { tabs?: boolean }) {
  const location = useLocation();
  const nav = useNavigate();
  const navType = useNavigationType();
  const [showCampaign, setShowCampaign] = useState(() => !!(location.state as { fromLogin?: boolean } | null)?.fromLogin);
  const closeCampaign = useCallback(() => {
    setShowCampaign(false);
    nav(location.pathname, { replace: true, state: null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
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
      {showCampaign ? <CampaignInterstitial onDone={closeCampaign} /> : <TermsGate />}
    </div>
  );
}

function Sidebar() {
  const { partner } = usePartner();
  return (
    <aside className="sidebar" aria-label="Navigation principale">
      <Link to="/" className="sidebar-brand" aria-label="MedClick — accueil">
        <Logo size={34} />
        <span className="sidebar-wordmark"><Wordmark size={21} /></span>
      </Link>
      <Link to="/ajouter" className="sidebar-cta" aria-label="Encoder une activité">
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
      {/* Sans partenaire, l'encadré disparaît. */}
      {partner && (
        <Link to="/partenaires" className="sidebar-partner">
          <span className="small muted" style={{ fontWeight: 600 }}>Avec le soutien de</span>
          <SponsorMark logoSize={20} textSize={17} />
        </Link>
      )}
    </aside>
  );
}
