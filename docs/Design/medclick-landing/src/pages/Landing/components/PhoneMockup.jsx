import { Icon } from './Icons';
import Logo from './Logo';

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

/* Écrans d'exemple : reprennent l'Accueil et « Mes activités » de l'application, avec des données fictives. */

function HomeScreen() {
  return (
    <div className="ps">
      <div className="ps-head">
        <Logo size={22} showWordmark={false} />
        <span className="ps-wm">Med<span>Click</span></span>
        <span className="ps-bell"><Icon name="bell" size={16} /><i /></span>
        <span className="ps-avatar">L</span>
      </div>
      <div>
        <div className="ps-h1">Bonjour Léa</div>
        <div className="ps-muted">Belle progression cette année !</div>
      </div>
      <div className="ps-card">
        <div className="ps-title"><Icon name="chart" size={13} strokeWidth={2.6} />Mon année (3e année)</div>
        <div className="ps-kpis">
          <Kpi tone="blue" icon="scalpel" value="124" label="interventions" />
          <Kpi tone="green" icon="hand" value="47" label="en première main" />
          <Kpi tone="purple" icon="stethoscope" value="18" label="consultations" />
          <Kpi tone="ring" value="72 %" label="du carnet complété" />
        </div>
        <div className="ps-streak">
          <span className="ps-flame"><Icon name="flame" size={14} /></span>6 semaines à jour
          <span className="ps-streak-chev"><Icon name="chevron-right" size={12} strokeWidth={2.2} /></span>
        </div>
      </div>
      <div className="ps-title-row">
        <span className="ps-title"><Icon name="calendar" size={13} />Cette semaine</span>
        <span className="ps-link">Voir tout ›</span>
      </div>
      <div className="ps-tiles">
        <Tile tone="blue" icon="scalpel" value="12" label="interventions encodées" />
        <Tile tone="green" icon="hand" value="6" label="première main" />
        <Tile tone="red" icon="calendar" value="1" label="jour sans activité" />
      </div>
      <div className="ps-btn">Compléter ma semaine <Icon name="arrow" size={14} strokeWidth={2.4} /></div>
      <span className="ps-fab"><Icon name="plus" size={18} strokeWidth={2.6} /></span>
      <TabBar active="Accueil" />
    </div>
  );
}

function ListScreen() {
  // [acte, moment, rôle, lieu, couleur du rôle, icône]
  const rows = [
    ['Prothèse totale du genou', "Aujourd'hui", '1re main', 'Genou', 'g', 'knee'],
    ['Ostéosynthèse de la cheville', "Aujourd'hui", '1re main aidée', 'Cheville', 'b', 'ankle'],
    ['Plastie du ligament croisé antérieur', 'Hier', '1re main', 'Genou', 'g', 'knee'],
    ['Prothèse totale de hanche', 'Hier', '2e main', 'Hanche', 'p', 'hip'],
    ['Hallux valgus', 'Lundi', '1re main aidée', 'Pied', 'b', 'foot'],
    ['Appendicectomie', 'Lundi', '1re main', 'Digestif', 'g', 'dig'],
  ];
  return (
    <div className="ps">
      <div className="ps-top">
        <Icon name="chevron-left" size={16} strokeWidth={2.2} />
        <div className="ps-h1">Mes activités</div>
        <span />
      </div>
      <div className="ps-seg ps-seg--4"><span className="on">Interventions</span><span>Consult.</span><span>Gardes</span><span>Formations</span></div>
      <div className="ps-search"><Icon name="search" size={12} />Rechercher une intervention ou un code…</div>
      <div className="ps-title">Récemment ajoutées</div>
      <div className="ps-card ps-card--list">
        {rows.map(([t, when, role, where, c, icon]) => (
          <div className="ps-row" key={t}>
            <span className={`ps-sq lp-tone-${icon === 'dig' ? 'purple' : 'blue'}`}><Icon name={icon} size={15} /></span>
            <span className="ps-row-t">
              <b><span className={`ps-dot ps-dot--${c}`} />{t}</b>
              <small>{when} · {role} · {where}</small>
            </span>
          </div>
        ))}
      </div>
      <span className="ps-fab"><Icon name="plus" size={18} strokeWidth={2.6} /></span>
      <TabBar active="Activités" />
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

function Tile({ tone, icon, value, label }) {
  return (
    <div className={`ps-tile lp-tone-${tone}`}>
      <Icon name={icon} size={15} />
      <b>{value}</b>
      <small>{label}</small>
    </div>
  );
}

/** Barre d'onglets de l'application : Accueil, Activités, Ma semaine, Progression, Plus. */
const TABS = [['Accueil', 'home'], ['Activités', 'scalpel'], ['Ma semaine', 'calendar'], ['Progression', 'chart'], ['Plus', 'dots']];

function TabBar({ active }) {
  return (
    <div className="ps-tabbar">
      {TABS.map(([label, icon]) => (
        <span key={label} className={label === active ? 'on' : ''}><Icon name={icon} size={16} />{label}</span>
      ))}
    </div>
  );
}
