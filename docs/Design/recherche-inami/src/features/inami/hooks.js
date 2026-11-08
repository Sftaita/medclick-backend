import { useCallback, useEffect, useRef, useState } from 'react';

/** Valeur retardée (évite de filtrer à chaque touche sur les grandes listes). */
export function useDebouncedValue(value, delay = 150) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

/**
 * Charge une ressource asynchrone avec états loading / error, en ignorant
 * les réponses arrivées après un changement de paramètre.
 */
export function useAsync(fn, deps) {
  const [state, setState] = useState({ data: null, loading: true, error: null });
  const reqId = useRef(0);
  const run = useCallback(() => {
    const id = ++reqId.current;
    setState((s) => ({ data: s.data, loading: true, error: null }));
    Promise.resolve()
      .then(fn)
      .then((data) => { if (id === reqId.current) setState({ data, loading: false, error: null }); })
      .catch((error) => { if (id === reqId.current) setState({ data: null, loading: false, error }); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => { run(); }, [run]);
  return { ...state, retry: run };
}

/** Garde le focus dans un conteneur (modal), Échap pour fermer, focus rendu à la fermeture. */
export function useFocusTrap(open, containerRef, onEscape) {
  useEffect(() => {
    if (!open) return undefined;
    const previous = document.activeElement;
    const el = containerRef.current;
    const focusables = () =>
      Array.from(el?.querySelectorAll('a[href], button:not([disabled]), input, select, [tabindex]:not([tabindex="-1"])') ?? []);
    const first = el?.querySelector('[data-autofocus]') ?? focusables()[0];
    first?.focus();
    const onKey = (e) => {
      if (e.key === 'Escape') { e.preventDefault(); onEscape?.(); return; }
      if (e.key !== 'Tab') return;
      const items = focusables();
      if (!items.length) return;
      const a = items[0], z = items[items.length - 1];
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
      else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    };
    document.addEventListener('keydown', onKey);
    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
      if (previous && previous.focus) previous.focus();
    };
  }, [open, containerRef, onEscape]);
}
