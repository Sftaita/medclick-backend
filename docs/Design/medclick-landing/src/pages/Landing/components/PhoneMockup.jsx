import { Icon } from './Icons';

/**
 * Cadre de téléphone pour le hero.
 * - `src` fourni : affiche la capture d'écran (390 × 844 recommandé).
 * - sinon : affiche un écran d'exemple dessiné en JSX (`variant` = 'home' | 'list').
 */
export default function PhoneMockup({ src, alt = '', variant = 'home', className = '' }) {
  return (
    <div className={`lp-phone ${className}`}>
      <div className="lp-screen">
        {src ? <img src={src} alt={alt} className="lp-screen-img" /> : variant === 'list' ? <ListScreen /> : <HomeScreen />}
      </div>
    </div>
  );
}

/* Écrans d'exemple : données fictives d'illustration, comme dans les maquettes. */

function HomeScreen() {
  return (
    <div className="ps">
      <div className="ps-head">
        <div>
          <div className="ps-h1">Bonjour Léa</div>
          <div className="ps-muted">Belle progression cette année !</div>
        </div>
        <span className="ps-bell"><Icon name="bell" size={16} /></span>
        <span className="ps-avatar">L</span>
      </div>
      <div className="ps-card">
        <div className="ps-title">Mon année</div>
        <div className="ps-kpis">
          <Kpi tone="blue" icon="scalpel" value="124" label="interventions" />
          <Kpi tone="green" icon="hand" value="47" label="en 1re main" />
          <Kpi tone="green" icon="stethoscope" value="18" label="consultations" />
          <Kpi tone="ring" value="72 %" label="du carnet" />
        </div>
        <div className="ps-streak"><span className="ps-flame"><Icon name="flame" size={14} /></span>6 semaines à jour</div>
      </div>
      <div className="ps-title" style={{ marginTop: 4 }}>Cette semaine</div>
      <div className="ps-tiles">
        <Tile tone="blue" value="12" label="encodées" />
        <Tile tone="green" value="6" label="1re main" />
        <Tile tone="red" value="1" label="jour manquant" />
      </div>
      <div className="ps-btn">Compléter ma semaine <Icon name="arrow" size={14} strokeWidth={2.4} /></div>
      <TabBar />
    </div>
  );
}

function ListScreen() {
  const rows = [
    ['Arthroplastie totale du genou', '1re main', 'g'],
    ['Ostéosynthèse de cheville', '1re main aidée', 'b'],
    ['Arthroscopie du genou', '1re main', 'g'],
    ['Prothèse totale de hanche', '2e main', 'p'],
    ['Suture tendineuse', '1re main aidée', 'b'],
    ['Ablation de matériel', '1re main', 'g'],
  ];
  return (
    <div className="ps">
      <div className="ps-h1" style={{ marginTop: 6 }}>Mes activités</div>
      <div className="ps-seg"><span className="on">Interventions</span><span>Consult.</span><span>Gardes</span></div>
      <div className="ps-card" style={{ gap: 0, padding: '4px 12px' }}>
        {rows.map(([t, r, c]) => (
          <div className="ps-row" key={t}>
            <span className={`ps-dot ps-dot--${c}`} />
            <span className="ps-row-t"><b>{t}</b><small>{r}</small></span>
          </div>
        ))}
      </div>
      <TabBar />
    </div>
  );
}

function Kpi({ tone, icon, value, label }) {
  return (
    <div className="ps-kpi">
      {tone === 'ring' ? (
        <svg width="26" height="26" viewBox="0 0 44 44" aria-hidden="true">
          <circle cx="22" cy="22" r="18.5" fill="none" stroke="#E6ECF5" strokeWidth="7" />
          <circle cx="22" cy="22" r="18.5" fill="none" stroke="#1D7A4C" strokeWidth="7" strokeLinecap="round" strokeDasharray="83.7 116.2" transform="rotate(-90 22 22)" />
        </svg>
      ) : (
        <span className={`ps-chip lp-tone-${tone}`}><Icon name={icon} size={13} /></span>
      )}
      <span><b>{value}</b><small>{label}</small></span>
    </div>
  );
}

function Tile({ tone, value, label }) {
  return (
    <div className={`ps-tile lp-tone-${tone}`}>
      <b>{value}</b>
      <small>{label}</small>
    </div>
  );
}

function TabBar() {
  return (
    <div className="ps-tabbar">
      {['Accueil', 'Activités', '', 'Semaine', 'Profil'].map((l, i) =>
        i === 2 ? <span key="fab" className="ps-fab">+</span> : <span key={l} className={i === 0 ? 'on' : ''}>{l}</span>
      )}
    </div>
  );
}
