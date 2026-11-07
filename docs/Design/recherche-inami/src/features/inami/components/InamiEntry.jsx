import '../inami.css';
import { useState } from 'react';
import { Icon } from './Icons';
import ProConfirmModal from './ProConfirmModal';
import { hasConfirmedPro, rememberConfirmedPro } from '../lib/storage';

/**
 * Bouton « Trouver un code INAMI » + fenêtre de confirmation.
 * À placer sur la page de connexion (variant="login") ou dans un bandeau public (variant="nav").
 *
 * onConfirmed : appelé quand le visiteur a confirmé → naviguez vers la page de recherche.
 */
export default function InamiEntry({ onConfirmed, variant = 'login', showHint = true }) {
  const [open, setOpen] = useState(false);

  const handleClick = () => {
    if (hasConfirmedPro()) onConfirmed?.();
    else setOpen(true);
  };

  return (
    <>
      <div className={`ia-entry ia-entry--${variant}`}>
        <button type="button" className="ia-entry-btn" onClick={handleClick} aria-haspopup="dialog">
          <Icon name="search" size={19} strokeWidth={2.4} />
          Trouver un code INAMI
        </button>
        {showHint && variant === 'login' && (
          <span className="ia-entry-hint">Sans compte · pour les professionnels de la santé</span>
        )}
      </div>
      <ProConfirmModal
        open={open}
        onConfirm={() => { rememberConfirmedPro(); setOpen(false); onConfirmed?.(); }}
        onDecline={() => setOpen(false)}
      />
    </>
  );
}
