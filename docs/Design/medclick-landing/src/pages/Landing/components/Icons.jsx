/* Icônes au trait, reprises des maquettes MedClick. Toutes décoratives (aria-hidden). */

const base = {
  fill: 'none',
  stroke: 'currentColor',
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
  'aria-hidden': true,
  viewBox: '0 0 24 24',
};

export function Icon({ name, size = 22, strokeWidth = 2 }) {
  const p = { ...base, width: size, height: size, strokeWidth };
  switch (name) {
    case 'scalpel':
      return (<svg {...p}><path d="M4 20l4-1L19 8l-3-3L5 16z" /><path d="M14 7l3 3" /></svg>);
    case 'stethoscope':
      return (<svg {...p}><path d="M5 3H4v5a5 5 0 0010 0V3h-1" /><path d="M9 13v2a5 5 0 0010 0v-2" /><circle cx="19" cy="11" r="2" /></svg>);
    case 'moon':
      return (<svg {...p}><path d="M20 14.5A8 8 0 019.5 4a8 8 0 1010.5 10.5z" /></svg>);
    case 'cap':
      return (<svg {...p}><path d="M2 9l10-5 10 5-10 5z" /><path d="M6 11v5c3 2 9 2 12 0v-5" /></svg>);
    case 'check':
      return (<svg {...p}><path d="M20 6L9 17l-5-5" /></svg>);
    case 'arrow':
      return (<svg {...p}><path d="M5 12h14M13 6l6 6-6 6" /></svg>);
    case 'chevron-down':
      return (<svg {...p}><path d="M6 9l6 6 6-6" /></svg>);
    case 'search':
      return (<svg {...p}><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></svg>);
    case 'excel':
      return (<svg {...p}><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v4h4M9 12l4 5M13 12l-4 5" /></svg>);
    case 'hand':
      return (<svg {...p}><path d="M8 13V5.5a1.5 1.5 0 013 0V11" /><path d="M11 11V4a1.5 1.5 0 013 0v7" /><path d="M14 11V5.5a1.5 1.5 0 013 0V15c0 3.5-2.5 6-6 6s-6-2.5-6-6v-3a1.5 1.5 0 013 0v1" /></svg>);
    case 'calendar':
      return (<svg {...p}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" /></svg>);
    case 'bell':
      return (<svg {...p}><path d="M6 16v-5a6 6 0 0112 0v5l2 2H4z" /><path d="M10 21h4" /></svg>);
    case 'flame':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 2.5c1.2 3.2 5.5 5.2 5.5 10.5a5.5 5.5 0 01-11 0c0-3 1.8-4.6 2.5-6.5 1 1.3 2 2 3 1.6-.8-2-.6-3.8 0-5.6z" />
        </svg>
      );
    case 'star':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" />
        </svg>
      );
    default:
      return null;
  }
}

/** Croix MedClick en aplat, utilisée en décor de fond. */
export function DecoCross({ size = 320, color = '#9FC0FF', className, style }) {
  return (
    <svg className={className} style={style} width={size} height={size} viewBox="0 0 64 64" aria-hidden="true">
      <rect x="22" y="3" width="20" height="58" rx="7" fill={color} />
      <rect x="3" y="22" width="58" height="20" rx="7" fill={color} />
    </svg>
  );
}
