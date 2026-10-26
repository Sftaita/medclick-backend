import { useId } from 'react';

/** Logo MedClick (croix + mot-symbole). `showWordmark={false}` : croix seule ; `showMark={false}` : texte seul. */
export default function Logo({ size = 34, fontSize = 22, showWordmark = true, showMark = true }) {
  const id = useId().replace(/:/g, '');
  return (
    <span className="lp-logo">
      {showMark && (
      <svg width={size} height={size} viewBox="0 0 64 64" aria-hidden="true">
        <defs>
          <linearGradient id={`${id}v`} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stopColor="#5B8CFF" />
            <stop offset="1" stopColor="#2447D6" />
          </linearGradient>
          <linearGradient id={`${id}h`} x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stopColor="#5FD6C8" />
            <stop offset="1" stopColor="#3E7BF2" />
          </linearGradient>
        </defs>
        <rect x="22" y="3" width="20" height="58" rx="7" fill={`url(#${id}v)`} opacity="0.92" />
        <rect x="3" y="22" width="58" height="20" rx="7" fill={`url(#${id}h)`} opacity="0.82" />
        <rect x="22" y="22" width="20" height="20" fill="#1E3FC4" opacity="0.55" />
        <rect x="25" y="6" width="5" height="14" rx="2.5" fill="#fff" opacity="0.35" />
      </svg>
      )}
      {showWordmark && (
        <span className="lp-wordmark" style={{ fontSize, letterSpacing: `${-fontSize * 0.03}px` }}>
          Med<span>Click</span>
        </span>
      )}
    </span>
  );
}
