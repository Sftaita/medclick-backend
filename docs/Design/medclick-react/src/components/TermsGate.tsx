import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Checkbox } from './ui';

/**
 * Bloque l'application tant que la dernière version des CGU n'est pas acceptée
 * (`GET /api/terms-conditions`, `PUT /api/acceptTerms`).
 */
export function TermsGate() {
  const nav = useNavigate();
  const { data: profile, setData } = useAsync(() => api.getProfile(), []);
  const { data: terms } = useAsync(() => (profile && !profile.termsAccepted ? api.getTerms() : Promise.resolve(null)), [profile?.termsAccepted]);
  const [checked, setChecked] = useState(false);
  const [busy, setBusy] = useState(false);
  if (!profile || profile.termsAccepted || !terms) return null;

  const accept = async () => {
    setBusy(true);
    try {
      await api.acceptTerms();
      setData({ ...profile, termsAccepted: true });
    } finally {
      setBusy(false);
    }
  };
  const logout = async () => { await api.logout(); nav('/connexion', { replace: true }); };
  const published = new Date(terms.publishedAt + 'T12:00:00').toLocaleDateString('fr-BE', { day: 'numeric', month: 'long', year: 'numeric' });

  return (
    <div className="login-sheet-backdrop">
      <div className="login-sheet mc-enter-up" role="dialog" aria-modal="true" aria-label="Conditions d'utilisation mises à jour" style={{ gap: 14 }}>
        <div className="stack" style={{ gap: 6 }}>
          <h2 className="h2" style={{ fontSize: 20 }}>Conditions d'utilisation mises à jour</h2>
          <p className="muted" style={{ fontSize: 14, lineHeight: 1.45 }}>Version publiée le {published}. Lisez-les et acceptez-les pour continuer à utiliser MedClick.</p>
        </div>
        <div tabIndex={0} aria-label="Texte des conditions d'utilisation"
          style={{ maxHeight: 330, overflowY: 'auto', border: '1px solid var(--mc-border)', borderRadius: 14, padding: 14, background: 'var(--mc-bg)', fontSize: 13, lineHeight: 1.55, whiteSpace: 'pre-line' }}>
          {terms.content}
        </div>
        <Checkbox checked={checked} onChange={setChecked}>J'ai lu et j'accepte les conditions d'utilisation</Checkbox>
        <button type="button" className="btn btn--primary btn--block" disabled={!checked || busy} onClick={accept}>Accepter et continuer</button>
        <button type="button" className="btn btn--ghost" style={{ color: 'var(--mc-muted)', minHeight: 36 }} onClick={logout}>Se déconnecter</button>
      </div>
    </div>
  );
}
