import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { Logo, SponsorMark, Wordmark } from '@/components/ui';
import { usePartner } from '@/hooks/usePartner';
import { api } from '@/api';
import './LoginPage.css';

export default function LoginPage() {
  const nav = useNavigate();
  const { partner } = usePartner();
  const [step, setStep] = useState<'splash' | 'form'>('splash');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.login(email, password);
      // La campagne publicitaire éventuelle s'affiche juste après la connexion (AppLayout).
      nav('/', { replace: true, state: { fromLogin: true } });
    } catch (err) {
      setError((err as Error).message || 'Connexion impossible');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="app-shell login">
      <div className="login-halo" />
      <img className="login-photo" src="/images/login-photo.jpg" alt="Chirurgiens en tenue de bloc pendant une intervention" />
      <div className="login-photo-fade" />
      <svg className="login-wave" viewBox="0 0 390 90" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 30 C 110 70, 260 72, 390 20 L390 90 L0 90 Z" fill="#F5F8FD" />
      </svg>

      <div className="login-top">
        <Logo size={80} />
        <Wordmark size={42} />
        <p className="login-tagline">Apprendre aujourd'hui,<br />la pratique de demain</p>
        <h1 className="login-title">Connexion</h1>
        {/* Sans partenaire, la carte reste (elle équilibre la photo) avec le seul message MedClick. */}
        <div className="login-sponsor">
          {partner ? (
            <>
              <span className="muted small">Avec le soutien de</span>
              <SponsorMark logoSize={34} textSize={28} />
              <span className="muted small">Votre carnet de stage numérique</span>
            </>
          ) : (
            <>
              <span style={{ fontSize: 18, fontWeight: 800, letterSpacing: -0.3, textAlign: 'center' }}>Votre carnet de stage numérique</span>
              <span className="muted small center">Interventions, consultations, gardes et formations</span>
            </>
          )}
        </div>
      </div>

      <div className="login-bottom">
        <button type="button" className="btn btn--primary btn--block login-cta" onClick={() => setStep('form')}>
          Continuer <Icon name="arrowR" size={20} stroke={2.4} />
        </button>
        <span className="login-motto">Le carnet de stage qui vous suit<br />tout au long de votre formation</span>
      </div>

      {step === 'form' && (
        <div className="login-sheet-backdrop" onClick={() => setStep('splash')}>
          <form className="login-sheet mc-enter-up" onSubmit={submit} onClick={(e) => e.stopPropagation()} aria-label="Se connecter">
            <div className="row" style={{ justifyContent: 'space-between' }}>
              <h2 className="h2">Se connecter</h2>
              <button type="button" className="icon-btn icon-btn--soft" style={{ alignSelf: 'center' }} aria-label="Fermer" onClick={() => setStep('splash')}>×</button>
            </div>
            <label className="fl">
              <span>Adresse e-mail</span>
              <input className="input" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
            </label>
            <label className="fl">
              <span>Mot de passe</span>
              <input className="input" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            </label>
            <Link to="/mot-de-passe-oublie" style={{ alignSelf: 'flex-end', fontSize: 13, fontWeight: 600, textDecoration: 'none', marginTop: -4 }}>Mot de passe oublié ?</Link>
            {error && (
              <p className="banner banner--danger" role="alert" style={{ flexDirection: 'row', alignItems: 'center', fontSize: 13, fontWeight: 600 }}>
                <Icon name="alert" size={18} />{error}
              </p>
            )}
            <button className="btn btn--primary btn--block" disabled={busy}>
              {busy ? <><span className="spinner" /> Connexion…</> : 'Se connecter'}
            </button>
            <span className="muted center" style={{ fontSize: 13 }}>Pas encore de compte ? <Link to="/inscription" style={{ fontWeight: 700, textDecoration: 'none' }}>Créer un compte</Link></span>
          </form>
        </div>
      )}
    </div>
  );
}
