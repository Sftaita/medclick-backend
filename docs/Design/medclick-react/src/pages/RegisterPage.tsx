import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { Icon } from '@/components/Icon';
import { Checkbox, TopBar } from '@/components/ui';
import { InfoCard, StatusHero } from '@/components/StatusHero';
import { REGISTER_SPECIALITIES, SPECIALITY_LABEL } from '@/data/referentiel';
import type { RegisterRequest } from '@/types';
import './LoginPage.css';

/** Inscription (`POST /api/users`) puis invitation à activer le compte par e-mail. */
export default function RegisterPage() {
  const [form, setForm] = useState<RegisterRequest>({ firstName: '', lastName: '', email: '', speciality: 'ortho', password: '' });
  const [confirm, setConfirm] = useState('');
  const [terms, setTerms] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const set = (p: Partial<RegisterRequest>) => setForm({ ...form, ...p });

  const lengthOk = form.password.length >= 6 && form.password.length <= 50;
  const same = form.password.length > 0 && form.password === confirm;
  const valid = form.firstName && form.lastName && form.email && lengthOk && same && terms;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!valid) return;
    setBusy(true);
    setError(null);
    try {
      await api.register(form);
      setSent(true);
    } catch (err) {
      setError((err as Error).message || 'Inscription impossible');
    } finally {
      setBusy(false);
    }
  };

  if (sent) {
    return (
      <div className="app-shell login account">
        <div className="login-halo" style={{ top: 520 }} />
        <div className="screen-body">
          <StatusHero icon="mail" tone="blue" title="Activez votre compte">
            <p className="muted" style={{ fontSize: 15, lineHeight: 1.5 }}>Un e-mail d'activation a été envoyé à <strong style={{ color: 'var(--mc-ink)' }}>{form.email}</strong>. Cliquez sur le lien qu'il contient, puis connectez-vous.</p>
            <InfoCard>Tant que le compte n'est pas activé, la connexion est refusée. Rien reçu ? Vérifiez vos courriers indésirables.</InfoCard>
          </StatusHero>
        </div>
        <div className="bottom-action" style={{ background: 'transparent', borderTop: 0 }}>
          <Link to="/connexion" className="btn btn--primary login-cta">Aller à la connexion <Icon name="arrowR" size={20} stroke={2.4} /></Link>
        </div>
      </div>
    );
  }

  return (
    <div className="app-shell login account">
      <div className="login-halo" style={{ top: 640 }} />
      <TopBar title="" back="/connexion" />
      <form className="screen-body" style={{ gap: 14, paddingTop: 4 }} onSubmit={submit} aria-label="Créer un compte">
        <div className="stack" style={{ gap: 6 }}>
          <h1 className="h1">Créer un compte</h1>
          <p className="muted" style={{ fontSize: 15 }}>Votre carnet de stage numérique, de la 1re à la dernière année.</p>
        </div>
        <div className="grid-2" style={{ gap: 10 }}>
          <label className="fl"><span>Prénom</span><input className="input" required maxLength={50} autoComplete="given-name" value={form.firstName} onChange={(e) => set({ firstName: e.target.value })} /></label>
          <label className="fl"><span>Nom</span><input className="input" required maxLength={50} autoComplete="family-name" value={form.lastName} onChange={(e) => set({ lastName: e.target.value })} /></label>
        </div>
        <label className="fl"><span>Adresse e-mail</span><input className="input" type="email" required autoComplete="email" value={form.email} onChange={(e) => set({ email: e.target.value })} /></label>
        <label className="fl"><span>Spécialité</span>
          <select className="select" value={form.speciality} onChange={(e) => set({ speciality: e.target.value as RegisterRequest['speciality'] })}>
            {REGISTER_SPECIALITIES.map((s) => <option key={s} value={s}>{SPECIALITY_LABEL[s]}</option>)}
          </select>
        </label>
        <label className="fl"><span>Mot de passe</span><input className="input" type="password" required autoComplete="new-password" value={form.password} onChange={(e) => set({ password: e.target.value })} /></label>
        <label className="fl"><span>Confirmer le mot de passe</span><input className="input" type="password" required autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} /></label>
        <PasswordRules lengthOk={lengthOk} same={same} />
        <Checkbox checked={terms} onChange={setTerms}>J'accepte les <Link to="/cgu" style={{ fontWeight: 700, textDecoration: 'none' }}>conditions d'utilisation</Link></Checkbox>
        {error && <p className="banner banner--danger" role="alert">{error}</p>}
        <button className="btn btn--primary btn--block" disabled={!valid || busy} style={{ marginTop: 4 }}>
          {busy ? <><span className="spinner" /> Création…</> : 'Créer mon compte'}
        </button>
        <span className="muted center" style={{ fontSize: 13 }}>Déjà un compte ? <Link to="/connexion" style={{ fontWeight: 700, textDecoration: 'none' }}>Se connecter</Link></span>
      </form>
    </div>
  );
}

/** Règles du backend : 6 à 50 caractères, confirmation identique. */
export function PasswordRules({ lengthOk, same }: { lengthOk: boolean; same: boolean }) {
  const rule = (ok: boolean, label: string) => (
    <span className="row small" style={{ gap: 8, fontWeight: 600, color: ok ? 'var(--mc-success)' : 'var(--mc-muted)' }}>
      <Icon name={ok ? 'check' : 'info'} size={16} stroke={ok ? 3 : 2} />{label}
    </span>
  );
  return <div className="stack" style={{ gap: 6 }}>{rule(lengthOk, 'Entre 6 et 50 caractères')}{rule(same, 'Les deux mots de passe sont identiques')}</div>;
}
