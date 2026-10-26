// Exemple d'intégration avec react-router-dom v6.
import { BrowserRouter, Routes, Route, Link } from 'react-router-dom';
import LandingPage from '../src/pages/Landing/LandingPage';

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/"
          element={
            <LandingPage
              LinkComponent={Link}
              config={{ contactEmail: '' /* ex. 'contact@medclick.be' */ }}
              // partner={{ name: 'Nom du partenaire', logoSrc: '/partners/logo.svg', href: '/partenaires' }}
              // screenshots={{ front: '/landing/accueil.png', back: '/landing/interventions.png' }}
            />
          }
        />
        {/* <Route path="/connexion" element={<LoginPage />} /> … */}
      </Routes>
    </BrowserRouter>
  );
}
