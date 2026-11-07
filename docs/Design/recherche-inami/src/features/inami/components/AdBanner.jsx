import { Icon } from './Icons';

/**
 * Emplacement publicitaire.
 * campaign = { desktopSrc, mobileSrc, href, alt, background? } (système de campagnes MedClick)
 * - visuels affichés en entier (contain), proportions fixes : 8:1 grand écran, 16:5 smartphone ;
 * - sans campagne : encart MedClick (houseHref) ; houseHref = null pour ne rien afficher.
 */
export default function AdBanner({ campaign, houseHref = '/', LinkComponent, preview = false }) {
  if (!campaign && preview) {
    return (
      <aside className="ia-ad" aria-label="Publicité">
        <div className="ia-ad-box ia-ad-box--ph">
          <span className="ia-ad-ph ia-ad-ph--d"><Icon name="image" size={28} strokeWidth={1.6} /><span><strong>[Visuel de la campagne]</strong> · grand écran 1200 × 150</span></span>
          <span className="ia-ad-ph ia-ad-ph--m"><strong>[Visuel de la campagne]</strong><span>smartphone 640 × 200</span></span>
          <span className="ia-ad-tag">Publicité</span>
        </div>
      </aside>
    );
  }

  if (!campaign) {
    if (!houseHref) return null;
    const L = LinkComponent;
    const link = L
      ? <L to={houseHref} className="ia-house-link">Découvrir MedClick ›</L>
      : <a href={houseHref} className="ia-house-link">Découvrir MedClick ›</a>;
    return (
      <aside className="ia-ad ia-house" aria-label="MedClick">
        <svg className="ia-house-ic" width="34" height="34" viewBox="0 0 64 64" aria-hidden="true">
          <rect x="22" y="3" width="20" height="58" rx="7" fill="#5B8CFF" />
          <rect x="3" y="22" width="58" height="20" rx="7" fill="#5FD6C8" opacity="0.85" />
        </svg>
        <span className="ia-house-t">
          <strong>Vous êtes médecin assistant ?</strong> Encodez vos interventions et exportez votre carnet de stage officiel avec MedClick.
        </span>
        {link}
      </aside>
    );
  }

  const { desktopSrc, mobileSrc, href, alt = 'Publicité', background = '#EEF3FC' } = campaign;
  const img = (
    <picture>
      {mobileSrc && <source media="(max-width: 760px)" srcSet={mobileSrc} />}
      <img src={desktopSrc || mobileSrc} alt={alt} className="ia-ad-img" loading="eager" decoding="async" />
    </picture>
  );
  return (
    <aside className="ia-ad" aria-label="Publicité">
      {href ? (
        <a href={href} target="_blank" rel="sponsored noopener noreferrer" className="ia-ad-box" style={{ background }}>
          {img}<span className="ia-ad-tag">Publicité</span>
        </a>
      ) : (
        <div className="ia-ad-box" style={{ background }}>{img}<span className="ia-ad-tag">Publicité</span></div>
      )}
    </aside>
  );
}
