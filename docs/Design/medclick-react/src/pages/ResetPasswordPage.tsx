import { useState, type FormEvent } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/api';
import { Icon } from '@/components/Icon';
import { Logo, Wordmark } from '@/components/ui';
import { StatusHero } from '@/components/StatusHero';
import { PasswordRules } from './RegisterPage';
import './LoginPage.css';

/**
 * Nouveau mot de passe, ouvert depuis le lien de l'e-mail : `/reinitialiser/:token?email=…`
 * (`POST /api/resetPassword`). Le lien expire au bout d'une heure et ne sert qu'une fois.
 */
export default function ResetPasswordPage() {
  const { token = '' } = useParams();
  const [params] = useSearchParams();
  const email = params.get('email') ?? '';
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [state, setState] = useState<'form' | 'done' | 'expired'>('form');
  const [error, setError] = useState<string | null>(null);
  const lengthOk = password.length >= 6 && password.length <= 50;
  const same = password.length > 0 && password === confirm;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!lengthOk || !same) return;
    setBusy(true);
    setError(null);
    try {
      setState(await api.resetPassword(email, token, password) === 'ok' ? 'done' : 'expired');
    } catch (err) {
      setError((err as Error).message || 'Modification impossible');
    } finally {
      setBusy(false);
    }
  };

  if (state !== 'form') {
    const done = state === 'done';
    return (
      <div className="app-shell login account">
        <div className="login-halo" style={{ top: 520 }} />
        <div className="screen-body">
          <StatusHero icon={done ? 'check' : 'clock'} tone={done ? 'green' : 'orange'} title={done ? 'Mot de passe modifié' : "Ce lien n'est plus valide"}>
            <p className="muted" style={{ fontSize: 15, lineHeight: 1.5 }}>
              {done ? 'Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.'
                : 'Il a expiré (au bout d\'une heure) ou a déjà été utilisé. Demandez un nouveau lien pour réinitialiser votre mot de passe.'}
            </p>
          </StatusHero>
        </div>
        <div className="bottom-action" style={{ background: 'transparent', borderTop: 0, paddingBottom: 34 }}>
          {done
            ? <Link to="/connexion" className="btn btn--primary login-cta">Se connecter <Icon name="arrowR" size={20} stroke={2.4} /></Link>
            : <><Link to="/mot-de-passe-oublie" className="btn btn--primary">Demander un nouveau lien</Link><Link to="/connexion" className="btn btn--ghost">Retour à la connexion</Link></>}
        </div>
      </div>
    );
  }

  return (
    <div className="app-shell login account">
      <div className="login-halo" style={{ top: 560 }} />
      <header className="topbar" style={{ gap: 8, paddingLeft: 20 }}><Logo size={28} /><Wordmark /></header>
      <form className="screen-body" style={{ gap: 18, paddingTop: 18 }} onSubmit={submit} aria-label="Choisir un nouveau mot de passe">
        <div className="stack" style={{ gap: 8 }}>
          <h1 className="h1">Nouveau mot de passe</h1>
          {email && <p className="muted" style={{ fontSize: 15 }}>Pour le compte <strong style={{ color: 'var(--mc-ink)' }}>{email}</strong>.</p>}
        </div>
        <PasswordField label="Nouveau mot de passe" value={password} onChange={setPassword} show={show} onToggle={() => setShow(!show)} />
        <PasswordField label="Confirmer le mot de passe" value={confirm} onChange={setConfirm} show={show} onToggle={() => setShow(!show)} />
        <PasswordRules lengthOk={lengthOk} same={same} />
        {error && <p className="banner banner--danger" role="alert">{error}</p>}
        <button className="btn btn--primary btn--block" disabled={!lengthOk || !same || busy}>
          {busy ? <><span className="spinner" /> Enregistrement…</> : 'Enregistrer le mot de passe'}
        </button>
        <span className="row muted small" style={{ justifyContent: 'center', gap: 6 }}><Icon name="clock" size={14} />Lien valable une heure après la demande</span>
      </form>
    </div>
  );
}

function PasswordField({ label, value, onChange, show, onToggle }: { label: string; value: string; onChange: (v: string) => void; show: boolean; onToggle: () => void }) {
  return (
    <label className="fl">
      <span>{label}</span>
      <span style={{ position: 'relative', display: 'flex' }}>
        <input className="input" type={show ? 'text' : 'password'} autoComplete="new-password" required value={value}
          onChange={(e) => onChange(e.target.value)} style={{ height: 48, paddingRight: 48 }} />
        <button type="button" className="icon-btn" style={{ position: 'absolute', right: 2, top: 2, color: 'var(--mc-muted)' }}
          aria-label={show ? 'Masquer le mot de passe' : 'Afficher le mot de passe'} onClick={onToggle}>
          <Icon name={show ? 'eyeOff' : 'eye'} size={20} />
        </button>
      </span>
    </label>
  );
}
