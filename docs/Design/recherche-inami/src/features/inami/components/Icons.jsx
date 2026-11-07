/* Icônes au trait, décoratives (aria-hidden). */
const base = { fill: 'none', stroke: 'currentColor', strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, viewBox: '0 0 24 24' };

export function Icon({ name, size = 20, strokeWidth = 2 }) {
  const p = { ...base, width: size, height: size, strokeWidth };
  switch (name) {
    case 'search': return <svg {...p}><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></svg>;
    case 'search-minus': return <svg {...p}><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4M8.5 11h5" /></svg>;
    case 'close': return <svg {...p}><path d="M6 6l12 12M18 6L6 18" /></svg>;
    case 'copy': return <svg {...p}><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a1 1 0 011-1h10" /></svg>;
    case 'check': return <svg {...p}><path d="M20 6L9 17l-5-5" /></svg>;
    case 'arrow-up': return <svg {...p}><path d="M12 19V5M6 11l6-6 6 6" /></svg>;
    case 'chevron-down': return <svg {...p}><path d="M6 9l6 6 6-6" /></svg>;
    case 'info': return <svg {...p}><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8v.5" /></svg>;
    case 'image': return <svg {...p}><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="M21 16l-5-5-9 9" /></svg>;
    case 'alert': return <svg {...p}><path d="M12 3l9.5 17h-19z" /><path d="M12 10v4M12 17v.5" /></svg>;
    case 'stethoscope': return <svg {...p}><path d="M5 3H4v5a5 5 0 0010 0V3h-1" /><path d="M9 13v2a5 5 0 0010 0v-2" /><circle cx="19" cy="11" r="2" /></svg>;
    default: return null;
  }
}

/** Logo MedClick (croix + mot-symbole). */
export function MedClickLogo({ size = 30, fontSize = 20 }) {
  return (
    <span className="ia-logo">
      <svg width={size} height={size} viewBox="0 0 64 64" aria-hidden="true">
        <defs>
          <linearGradient id="ia-logo-v" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#5B8CFF" /><stop offset="1" stopColor="#2447D6" /></linearGradient>
          <linearGradient id="ia-logo-h" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stopColor="#5FD6C8" /><stop offset="1" stopColor="#3E7BF2" /></linearGradient>
        </defs>
        <rect x="22" y="3" width="20" height="58" rx="7" fill="url(#ia-logo-v)" opacity="0.92" />
        <rect x="3" y="22" width="58" height="20" rx="7" fill="url(#ia-logo-h)" opacity="0.82" />
        <rect x="22" y="22" width="20" height="20" fill="#1E3FC4" opacity="0.55" />
        <rect x="25" y="6" width="5" height="14" rx="2.5" fill="#fff" opacity="0.35" />
      </svg>
      <span className="ia-wordmark" style={{ fontSize }}>Med<span>Click</span></span>
    </span>
  );
}
