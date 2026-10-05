import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { Icon } from '@/components/Icon';
import { DecoCross, TopBar } from '@/components/ui';
import { InfoCard, StatusHero } from '@/components/StatusHero';
import './LoginPage.css';

/** Demande de réinitialisation (`POST /api/forgottenPassword`) : même réponse que l'adresse existe ou non. */
export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e?: FormEvent) => {
    e?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.forgotPassword(email);
      setSent(true);
    } catch (err) {
      setError((err as Error).message || 'Envoi impossible, réessayez dans quelques minutes.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="app-shell login account">
      <div className="login-halo" style={{ top: 520 }} />
      <TopBar title="" back={sent ? undefined : '/connexion'} />
      {sent ? (
        <>
          <div className="screen-body">
            <StatusHero icon="mail" tone="blue" title="Vérifiez vos e-mails">
              <p className="muted" style={{ fontSize: 15, lineHeight: 1.5 }}>Si un compte MedClick existe pour <strong style={{ color: 'var(--mc-ink)' }}>{email}</strong>, vous allez recevoir un lien pour choisir un nouveau mot de passe.</p>
              <InfoCard>Le lien est valable <strong>1 heure</strong> et ne fonctionne qu'une seule fois. Rien reçu ? Vérifiez vos courriers indésirables.</InfoCard>
            </StatusHero>
          </div>
          <div className="bottom-action" style={{ background: 'transparent', borderTop: 0 }}>
            <button type="button" className="btn btn--secondary" disabled={busy} onClick={() => submit()}>Renvoyer l'e-mail</button>
            <Link to="/connexion" className="btn btn--ghost">Retour à la connexion</Link>
          </div>
        </>
      ) : (
        <form className="screen-body" style={{ gap: 18, paddingTop: 12 }} onSubmit={submit} aria-label="Demander un nouveau mot de passe">
          <DecoCross size={150} style={{ right: -36, top: 0, opacity: 0.18 }} />
          <span className="icon-chip icon-chip--lg"><Icon name="lock" size={28} /></span>
          <div className="stack" style={{ gap: 8 }}>
            <h1 className="h1">Mot de passe oublié ?</h1>
            <p className="muted" style={{ fontSize: 15, lineHeight: 1.5 }}>Indiquez l'adresse e-mail de votre compte. Nous vous enverrons un lien pour choisir un nouveau mot de passe.</p>
          </div>
          <label className="fl"><span>Adresse e-mail</span><input className="input" type="email" required autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} /></label>
          {error && <p className="banner banner--danger" role="alert">{error}</p>}
          <button className="btn btn--primary btn--block" disabled={busy}>
            {busy ? <><span className="spinner" /> Envoi…</> : <>Envoyer le lien <Icon name="arrowR" size={18} stroke={2.4} /></>}
          </button>
          <Link to="/connexion" className="btn btn--ghost">Retour à la connexion</Link>
        </form>
      )}
    </div>
  );
}
