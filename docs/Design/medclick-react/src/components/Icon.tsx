import type { ReactNode } from 'react';

// Jeu d'icônes au trait (24×24, currentColor). Ajouter une icône = ajouter une entrée ici.
const P: Record<string, ReactNode> = {
  plus: <path d="M12 5v14M5 12h14" />,
  minus: <path d="M5 12h14" />,
  chevL: <path d="M15 18l-6-6 6-6" />,
  chevR: <path d="M9 6l6 6-6 6" />,
  chevD: <path d="M6 9l6 6 6-6" />,
  arrowR: <path d="M5 12h14M13 6l6 6-6 6" />,
  external: <path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6" />,
  search: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></>,
  bell: <><path d="M6 16v-5a6 6 0 0112 0v5l2 2H4z" /><path d="M10 21h4" /></>,
  star: <path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z" />,
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" /></>,
  user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 4-6 8-6s8 2 8 6" /></>,
  users: <><circle cx="9" cy="8" r="3.5" /><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5" /><path d="M16 4.5a3.5 3.5 0 010 7M18 14.6c2.4.6 4 2.4 4 5.4" /></>,
  cap: <><path d="M2 9l10-5 10 5-10 5z" /><path d="M6 11v5c3 2 9 2 12 0v-5" /></>,
  hand: <><path d="M8 13V5.5a1.5 1.5 0 013 0V11" /><path d="M11 11V4a1.5 1.5 0 013 0v7" /><path d="M14 11V5.5a1.5 1.5 0 013 0V15c0 3.5-2.5 6-6 6s-6-2.5-6-6v-3a1.5 1.5 0 013 0v1" /></>,
  pen: <><path d="M4 20l4-1L19 8l-3-3L5 16z" /><path d="M14 7l3 3" /></>,
  stetho: <><path d="M5 3H4v5a5 5 0 0010 0V3h-1" /><path d="M9 13v2a5 5 0 0010 0v-2" /><circle cx="19" cy="11" r="2" /></>,
  moon: <path d="M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z" />,
  layers: <><path d="M12 3l9 5-9 5-9-5z" /><path d="M3 12.5l9 5 9-5" /><path d="M3 17l9 5 9-5" /></>,
  check: <path d="M20 6L9 17l-5-5" />,
  alert: <><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5M12 16.5v.5" /></>,
  info: <><circle cx="12" cy="12" r="9" /><path d="M12 11v5.5M12 7.5v.5" /></>,
  joint: <><path d="M9 2v5c0 2 1.2 3.2 3 3.2S15 9 15 7V2" /><path d="M9 22v-5c0-2 1.2-3.2 3-3.2s3 1.2 3 3.2v5" /></>,
  hip: <><circle cx="8" cy="6.5" r="3" /><path d="M10.5 8.5L14 12l3 10" /><path d="M14 12l-3 1" /></>,
  scope: <><path d="M4 20L13 11" /><circle cx="16" cy="8" r="3.5" /><path d="M18.5 5.5L21 3" /></>,
  meniscus: <><path d="M4 15c0-5 4-9 9-9 4 0 7 2 7 5" /><path d="M8 15c0-3 2.5-5 5-5 2.5 0 4 1 4 3" /><path d="M4 15h4" /></>,
  home: <path d="M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z" />,
  chart: <path d="M5 20V12M10 20V6M15 20v-9M20 20V9" />,
  trophy: <><path d="M8 4h8v5a4 4 0 01-8 0z" /><path d="M8 6H5a3 3 0 003 4M16 6h3a3 3 0 01-3 4M12 13v4M8 21h8M10 17h4v4h-4z" /></>,
  gear: <><circle cx="12" cy="12" r="3.2" /><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1" /></>,
  sync: <><path d="M4 12a8 8 0 0114-5.3L20 9M20 4v5h-5" /><path d="M20 12a8 8 0 01-14 5.3L4 15M4 20v-5h5" /></>,
  help: <><rect x="3" y="3" width="18" height="18" rx="4" /><path d="M9.5 9.5a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5M12 16.5v.5" /></>,
  doc: <><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v4h4M9 12h6M9 16h6" /></>,
  shield: <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" />,
  building: <><rect x="4" y="3" width="11" height="18" /><path d="M15 9h5v12h-5M7.5 7h4M7.5 11h4M7.5 15h4" /></>,
  bulb: <><path d="M9 18h6M10 21h4" /><path d="M12 3a6 6 0 00-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0012 3z" /></>,
  book: <path d="M4 19V5a2 2 0 012-2h13v14H6a2 2 0 00-2 2zm0 0a2 2 0 002 2h13" />,
  target: <><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" /><circle cx="12" cy="12" r="1" /></>,
  notebook: <><path d="M5 3h10l4 4v14H5z" /><path d="M9 9h6M9 13h6M9 17h3" /></>,
  trash: <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />,
  sliders: <><path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1" /><circle cx="15" cy="6" r="2" /><circle cx="9" cy="12" r="2" /><circle cx="17" cy="18" r="2" /></>,
  bolt: <path d="M13 2L4 14h7l-1 8 9-12h-7z" />,
};

const FILLED: Record<string, ReactNode> = {
  starFilled: <path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z" />,
  dots: <><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></>,
  flame: <path d="M12 2.5c1.2 3.2 5.5 5.2 5.5 10.5a5.5 5.5 0 01-11 0c0-3 1.8-4.6 2.5-6.5 1 1.3 2 2 3 1.6-.8-2-.6-3.8 0-5.6z" />,
};

export type IconName = keyof typeof P | keyof typeof FILLED;

export function Icon({ name, size = 20, stroke = 2, className }: { name: IconName; size?: number; stroke?: number; className?: string }) {
  const filled = FILLED[name];
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" className={className}
      fill={filled ? 'currentColor' : 'none'} stroke={filled ? 'none' : 'currentColor'}
      strokeWidth={stroke} strokeLinecap="round" strokeLinejoin="round">
      {filled ?? P[name]}
    </svg>
  );
}
