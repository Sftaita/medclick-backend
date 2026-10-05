import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { Logo, PartnerMark, Wordmark } from '@/components/ui';
import { api } from '@/api';
import './LoginPage.css';

export default function LoginPage() {
  const nav = useNavigate();
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
      nav('/', { replace: true });
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
        <div className="login-sponsor">
          <span className="muted small">Avec le soutien de</span>
          <span style={{ fontSize: 28 }}><PartnerMark size={34} /></span>
          <span className="muted small">Votre carnet de stage numérique</span>
        </div>
      </div>

      <div className="login-bottom">
        <button type="button" className="btn btn--primary btn--block login-cta" onClick={() => setStep('form')}>
          Continuer <Icon name="arrowR" size={20} stroke={2.4} />
        </button>
        <span className="login-motto">DES STAGES AUJOURD'HUI<br />POUR LES EXPERTS DE DEMAIN</span>
      </div>

      {step === 'form' && (
        <div className="login-sheet-backdrop" onClick={() => setStep('splash')}>
          <form className="login-sheet mc-enter-up" onSubmit={submit} onClick={(e) => e.stopPropagation()} aria-label="Se connecter">
            <h2 className="h2">Se connecter</h2>
            <label className="stack" style={{ gap: 6 }}>
              <span className="muted small">Adresse e-mail</span>
              <input className="input" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
            </label>
            <label className="stack" style={{ gap: 6 }}>
              <span className="muted small">Mot de passe</span>
              <input className="input" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            </label>
            {error && <p className="banner banner--danger" role="alert">{error}</p>}
            <button className="btn btn--primary btn--block" disabled={busy}>
              {busy ? <><span className="spinner" /> Connexion…</> : 'Se connecter'}
            </button>
          </form>
        </div>
      )}
    </div>
  );
}
