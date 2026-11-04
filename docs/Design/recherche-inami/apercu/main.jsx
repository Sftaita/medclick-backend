import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { InamiSearchPage } from '../src/features/inami';
import LoginPreview from './LoginPreview';
import AdminPreview from './AdminPreview';
import { realApi } from './realApi';

// Paramètre `ad` : campagne | encart | aucune
const ad = new URLSearchParams(location.search).get('ad') || 'campagne';
const CAMPAIGN = {
  desktopSrc: 'ad-desktop.svg',
  mobileSrc: 'ad-mobile.svg',
  href: 'https://example.org/',
  alt: 'Exemple de campagne (visuel fictif)',
  background: '#0F2A66',
};

// Routes inexistantes dans l'aperçu (accueil, inscription, conditions) : renvoyées à la connexion.
const KNOWN = ['/login', '/codes-inami', '/admin/publicites'];
function HashLink({ to, children, ...rest }) {
  return <a href={`#${KNOWN.includes(to) ? to : '/login'}`} {...rest}>{children}</a>;
}

function useHash() {
  const [hash, setHash] = useState(location.hash.slice(1) || '/login');
  useEffect(() => {
    const on = () => { setHash(location.hash.slice(1) || '/login'); window.scrollTo(0, 0); };
    window.addEventListener('hashchange', on);
    return () => window.removeEventListener('hashchange', on);
  }, []);
  return hash;
}

function App() {
  const route = useHash();
  const go = (to) => { location.hash = to; };
  if (route.startsWith('/admin')) return <AdminPreview />;
  if (route.startsWith('/codes-inami')) {
    return (
      <InamiSearchPage
        key={route}
        api={realApi}
        campaign={ad === 'campagne' ? CAMPAIGN : null}
        links={{ home: ad === 'aucune' ? null : '/', login: '/login', register: '/register', terms: '/conditions-utilisation' }}
        LinkComponent={HashLink}
        onDecline={() => go('/login')}
      />
    );
  }
  return <LoginPreview onInami={() => go('/codes-inami')} />;
}

createRoot(document.getElementById('root')).render(<App />);
