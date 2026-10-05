import { useEffect, useRef, useState } from 'react';
import { easeOutCubic } from './tokens';
import { useReducedMotion } from './useReducedMotion';

interface TweenOptions {
  from?: number;
  duration: number;
  delay?: number;
  /** false → n'anime pas (ex. donnée pas encore chargée) */
  enabled?: boolean;
  onDone?: () => void;
}

/**
 * Interpole de `from` vers `to` avec requestAnimationFrame.
 * Garantit que la valeur finale est EXACTEMENT `to` (la donnée backend).
 * Si `to` change ensuite (ex. 121 → 124 après enregistrement), anime depuis la valeur affichée.
 */
export function useTween(to: number, { from = 0, duration, delay = 0, enabled = true, onDone }: TweenOptions): number {
  const reduced = useReducedMotion();
  const [value, setValue] = useState(reduced || !enabled ? to : from);
  const current = useRef(value);
  const doneRef = useRef(onDone);
  doneRef.current = onDone;

  useEffect(() => {
    if (!enabled) return;
    if (reduced) {
      current.current = to;
      setValue(to);
      doneRef.current?.();
      return;
    }
    const start = current.current;
    if (start === to) { doneRef.current?.(); return; }
    let raf = 0;
    let t0 = 0;
    const step = (now: number) => {
      if (!t0) t0 = now;
      const x = Math.min(1, Math.max(0, (now - t0 - delay) / duration));
      const v = x >= 1 ? to : start + (to - start) * easeOutCubic(x);
      current.current = v;
      setValue(v);
      if (x < 1) raf = requestAnimationFrame(step);
      else doneRef.current?.();
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, [to, duration, delay, enabled, reduced]);

  return value;
}
