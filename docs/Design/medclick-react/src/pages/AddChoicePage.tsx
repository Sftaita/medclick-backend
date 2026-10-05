import { Link, useNavigate } from 'react-router-dom';
import { Icon, type IconName } from '@/components/Icon';

const OPTIONS: { to: string; title: string; sub: string; icon: IconName; tone: string }[] = [
  { to: '/interventions/ajouter', title: 'Une intervention', sub: 'Acte de la nomenclature INAMI', icon: 'pen', tone: 'blue' },
  { to: '/interventions/journee', title: 'Journée opératoire', sub: 'Plusieurs actes, mêmes date et superviseur', icon: 'layers', tone: 'blue' },
  { to: '/consultations/ajouter', title: 'Consultation', sub: 'Nombre de patients vus par demi-journée', icon: 'stetho', tone: 'purple' },
  { to: '/gardes/ajouter', title: 'Garde', sub: 'Début, fin et patients vus pendant la garde', icon: 'moon', tone: 'orange' },
  { to: '/formations/ajouter', title: 'Formation', sub: 'Staff, journal club, cours ou congrès', icon: 'cap', tone: 'green' },
];

/** Point d'entrée unique de l'encodage (bouton « + » de l'accueil et « Ajouter » de la barre latérale). */
export default function AddChoicePage() {
  const nav = useNavigate();
  const close = () => nav(-1);
  return (
    <div className="login-sheet-backdrop" onClick={close} style={{ position: 'fixed' }}>
      <div className="login-sheet mc-enter-up" role="dialog" aria-modal="true" aria-label="Que voulez-vous encoder ?"
        onClick={(e) => e.stopPropagation()} style={{ gap: 6, maxWidth: 480, margin: '0 auto' }}>
        <span style={{ alignSelf: 'center', width: 40, height: 5, borderRadius: 3, background: '#D6DEEA', marginBottom: 6 }} />
        <div className="row" style={{ justifyContent: 'space-between' }}>
          <h2 className="h2">Que voulez-vous encoder ?</h2>
          <button type="button" className="icon-btn icon-btn--soft" style={{ alignSelf: 'center' }} aria-label="Fermer" onClick={close}>×</button>
        </div>
        <nav className="list" aria-label="Type d'activité">
          {OPTIONS.map((o, i) => (
            <Link key={o.to} to={o.to} replace className="list-row" style={{ minHeight: 68, borderBottom: i === OPTIONS.length - 1 ? 0 : undefined }}>
              <span className={`icon-chip icon-chip--sq tone-${o.tone}`} style={{ width: 44, height: 44 }}><Icon name={o.icon} size={22} /></span>
              <span className="grow stack" style={{ gap: 2 }}>
                <span style={{ fontSize: 15, fontWeight: 700 }}>{o.title}</span>
                <span className="list-row-sub">{o.sub}</span>
              </span>
              <Icon name="chevR" size={18} />
            </Link>
          ))}
        </nav>
      </div>
    </div>
  );
}
