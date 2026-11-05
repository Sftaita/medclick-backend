import { InamiEntry } from '../src/features/inami';
import { MedClickLogo } from '../src/features/inami/components/Icons';
import './login.css';

/**
 * Réplique visuelle de la connexion actuelle (frontend/src/auth/pages/LoginPage.jsx + AuthLayout.jsx)
 * pour situer le nouveau bouton. Seul ajout : le séparateur « ou » et <InamiEntry />.
 */
export default function LoginPreview({ onInami }) {
  return (
    <div className="lp">
      <header className="lp-top">
        <a href="#/login" className="lp-brand" aria-label="MedClick — accueil"><MedClickLogo size={28} fontSize={20} /></a>
        <span className="lp-sp" />
        <a href="#/login" className="lp-link">Se connecter</a>
        <a href="#/login" className="lp-cta">S'enregistrer</a>
      </header>

      <div className="lp-grid">
        <div className="lp-photo">
          <img src="login-photo.jpg" alt="Chirurgiens en tenue de bloc pendant une intervention" />
          <span className="lp-photo-fade" aria-hidden="true" />
          <div className="lp-partner lp-partner--photo"><PartnerCard /></div>
        </div>

        <div className="lp-col">
          <span className="lp-blob" aria-hidden="true" />
          <div className="lp-card">
            <h1 className="lp-h1">Se connecter</h1>
            <p className="lp-sub">Pas encore inscrit ? <a href="#/login">S'enregistrer</a></p>
            <form className="lp-form" onSubmit={(e) => e.preventDefault()}>
              <input className="lp-input" type="email" placeholder="Email *" autoComplete="off" />
              <input className="lp-input" type="password" placeholder="Mot de passe *" autoComplete="off" />
              <div className="lp-row">
                <span>Les champs marqués d'une * sont obligatoires.</span>
                <span>Mot de passe oublié ? <a href="#/login">Réinitialiser</a></span>
              </div>
              <button type="submit" className="lp-btn">Se connecter</button>
            </form>

            {/* --- Nouveau --- */}
            <div className="lp-or" role="separator"><span>ou</span></div>
            <InamiEntry onConfirmed={onInami} />
          </div>
          <div className="lp-partner lp-partner--below"><PartnerCard /></div>
        </div>
      </div>
    </div>
  );
}

/** Emplacement existant E1/E2 (partenaire de la connexion), inchangé : neutralisé dans l'aperçu. */
function PartnerCard() {
  return (
    <div className="lp-pcard">
      <small>PARTENAIRE DE MEDCLICK</small>
      <span className="lp-plogo">[Logo du partenaire]</span>
      <p>Emplacement existant, inchangé</p>
      <span className="lp-pbtn">Découvrir notre partenaire →</span>
    </div>
  );
}
