import '../inami.css';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './Icons';
import { useFocusTrap } from '../hooks';

const EXIT_MS = 220;

/**
 * Fenêtre « Êtes-vous professionnel de la santé ? ».
 * Smartphone : panneau qui monte du bas. Tablette / ordinateur : fenêtre centrée.
 * Déclaration de l'utilisateur, pas une vérification.
 */
export default function ProConfirmModal({ open, onConfirm, onDecline }) {
  const [mounted, setMounted] = useState(open);
  const [closing, setClosing] = useState(false);
  const ref = useRef(null);
  const pending = useRef(null);

  useEffect(() => {
    if (open) { setMounted(true); setClosing(false); }
  }, [open]);

  const close = useCallback((cb) => {
    pending.current = cb;
    setClosing(true);
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    setTimeout(() => {
      setMounted(false);
      setClosing(false);
      pending.current?.();
    }, reduce ? 0 : EXIT_MS);
  }, []);

  const decline = useCallback(() => close(onDecline), [close, onDecline]);
  const confirm = useCallback(() => close(onConfirm), [close, onConfirm]);

  useFocusTrap(mounted && !closing, ref, decline);

  if (!mounted) return null;

  return createPortal(
    <div
      className={`ia-modal-back ${closing ? 'is-closing' : ''}`}
      onMouseDown={(e) => { if (e.target === e.currentTarget) decline(); }}
    >
      <section
        ref={ref}
        className="ia-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ia-modal-title"
        aria-describedby="ia-modal-desc"
      >
        <span className="ia-modal-grab" aria-hidden="true" />
        <button type="button" className="ia-modal-x" aria-label="Fermer" onClick={decline}>
          <Icon name="close" size={18} strokeWidth={2.6} />
        </button>
        <span className="ia-modal-ic" aria-hidden="true"><Icon name="stethoscope" size={34} /></span>
        <h2 className="ia-modal-h" id="ia-modal-title">Êtes-vous professionnel de la santé ?</h2>
        <p className="ia-modal-p" id="ia-modal-desc">
          La recherche des codes INAMI est destinée aux professionnels de la santé.
        </p>
        <div className="ia-modal-actions">
          <button type="button" className="ia-btn ia-btn--primary" data-autofocus onClick={confirm}>
            Oui, je suis professionnel de la santé
          </button>
          <button type="button" className="ia-btn ia-btn--secondary" onClick={decline}>Non</button>
        </div>
        <p className="ia-modal-note">
          <Icon name="info" size={16} strokeWidth={2.2} />
          Simple déclaration de votre part : aucun compte MedClick n'est nécessaire.
        </p>
      </section>
    </div>,
    document.body
  );
}
