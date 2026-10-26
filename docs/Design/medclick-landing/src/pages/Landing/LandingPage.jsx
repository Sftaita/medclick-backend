import { createContext, useContext } from 'react';
import './LandingPage.css';
import Logo from './components/Logo';
import PhoneMockup from './components/PhoneMockup';
import { Icon, DecoCross } from './components/Icons';
import {
  landingConfig,
  hero,
  activityTypes,
  steps,
  origin,
  faq,
  finalCta,
} from './landingContent';

/**
 * Page d'accueil publique MedClick (avant connexion).
 *
 * Props (toutes optionnelles) :
 * - config          : surcharge de `landingConfig` (routes, e-mail de contact, version)
 * - partner         : { name, logoSrc, href, tagline } — omis : le bloc partenaire est masqué
 * - screenshots     : { front, back } — URL de captures 390×844 ; omis : écrans d'exemple dessinés
 * - LinkComponent   : composant de lien du routeur (ex. `Link` de react-router) ; défaut : <a>
 */
const LinkCtx = createContext(null);

/** Lien interne : utilise le composant du routeur s'il est fourni, sinon un <a>. */
function AppLink({ to, children, ...rest }) {
  const LinkComponent = useContext(LinkCtx);
  return LinkComponent ? (
    <LinkComponent to={to} {...rest}>{children}</LinkComponent>
  ) : (
    <a href={to} {...rest}>{children}</a>
  );
}

export default function LandingPage({ config, partner, screenshots = {}, LinkComponent = null }) {
  const cfg = { ...landingConfig, ...config };
  const mailto = cfg.contactEmail ? `mailto:${cfg.contactEmail}` : null;

  return (
    <LinkCtx.Provider value={LinkComponent}>
    <div className="lp">
      {/* Navigation */}
      <header className="lp-nav">
        <div className="lp-wrap lp-nav-in">
          <a href="#top" className="lp-brand" aria-label="MedClick — haut de page">
            <Logo />
          </a>
          <nav className="lp-links" aria-label="Sections">
            <a href="#fonctionnalites">Fonctionnalités</a>
            <a href="#etapes">Comment ça marche</a>
            <a href="#faq">FAQ</a>
          </nav>
          <div className="lp-actions">
            <AppLink to={cfg.loginHref} className="lp-btn lp-btn--ghost">Se connecter</AppLink>
            <AppLink to={cfg.registerHref} className="lp-btn lp-btn--primary">Créer un compte</AppLink>
          </div>
        </div>
      </header>

      <main>
        {/* Hero */}
        <section className="lp-hero" id="top">
          <DecoCross className="lp-cross" size={520} color="#8FB0FF" />
          <div className="lp-wrap lp-hero-grid">
            <div>
              <span className="lp-eyebrow"><i />{hero.eyebrow}</span>
              <h1 className="lp-h1">
                {hero.titleStart} <span>{hero.titleAccent}</span>
              </h1>
              <p className="lp-lead">{hero.lead}</p>
              <div className="lp-cta-row">
                <AppLink to={cfg.registerHref} className="lp-btn lp-btn--primary lp-btn--lg">
                  {hero.primaryCta}
                  <Icon name="arrow" size={20} strokeWidth={2.4} />
                </AppLink>
                <AppLink to={cfg.loginHref} className="lp-btn lp-btn--line lp-btn--lg">{hero.secondaryCta}</AppLink>
              </div>
              <ul className="lp-trust">
                {hero.trust.map((t) => (
                  <li key={t}><Icon name="check" size={18} strokeWidth={2.6} />{t}</li>
                ))}
              </ul>
            </div>

            <div className="lp-stage" aria-hidden="true">
              <div className="lp-halo" />
              <PhoneMockup className="lp-phone--back" variant="list" src={screenshots.back} />
              <PhoneMockup className="lp-phone--front" variant="home" src={screenshots.front} />
              <div className="lp-float lp-float--a">
                <span className="lp-float-chip lp-tone-green"><Icon name="check" size={20} strokeWidth={2.8} /></span>
                <span><strong>Intervention enregistrée</strong><small>Prothèse totale de genou · 1re main</small></span>
              </div>
            </div>
          </div>
        </section>

        {/* Fonctionnalités */}
        <section className="lp-section lp-section--tight-top" id="fonctionnalites">
          <div className="lp-wrap">
            <span className="lp-kicker">Tout votre stage, au même endroit</span>
            <h2 className="lp-h2">Fini le carnet papier et l'Excel reconstitué en fin d'année.</h2>

            <div className="lp-types">
              {activityTypes.map((a) => (
                <div className="lp-type" key={a.key}>
                  <span className={`lp-chip lp-tone-${a.tone}`}><Icon name={a.icon} /></span>
                  <span><strong>{a.title}</strong><small>{a.sub}</small></span>
                </div>
              ))}
            </div>

            <div className="lp-bento">
              <article className="lp-card lp-span-4">
                <span className="lp-kicker lp-kicker--sm">Encodage</span>
                <h3>Une intervention encodée avant d'avoir quitté le vestiaire.</h3>
                <p>Recherchez l'acte dans la nomenclature INAMI, retrouvez vos favoris et vos chirurgiens, indiquez votre rôle. C'est tout.</p>
                <div className="lp-demo lp-demo--encode" aria-hidden="true">
                  <div>
                    <div className="lp-search"><Icon name="search" size={18} strokeWidth={2.2} />prothèse genou</div>
                    <div className="lp-result"><span>Arthroplastie totale du genou</span><span className="lp-fav"><Icon name="star" size={18} /></span></div>
                    <div className="lp-result"><span>Prothèse unicompartimentale</span></div>
                    <div className="lp-result"><span>Reprise de prothèse du genou</span></div>
                  </div>
                  <div>
                    <span className="lp-label">Votre rôle</span>
                    <div className="lp-roles">
                      <span className="lp-role lp-role--on"><i className="lp-role-1" />1re main</span>
                      <span className="lp-role"><i className="lp-role-3" />1re main aidée</span>
                      <span className="lp-role"><i className="lp-role-2" />2e main</span>
                    </div>
                    <div className="lp-surgeon"><span className="lp-avatar">JD</span>Chirurgien enregistré</div>
                  </div>
                </div>
              </article>

              <article className="lp-card lp-span-2">
                <span className="lp-kicker lp-kicker--sm">Ma semaine</span>
                <h3>Vous voyez tout de suite le jour qui manque.</h3>
                <p>Chaque jour de stage non complété est signalé, et votre série de semaines à jour vous aide à rester régulier.</p>
                <div className="lp-demo" aria-hidden="true">
                  <div className="lp-week">
                    {[['L', 'ok'], ['M', 'ok'], ['M', 'miss'], ['J', 'ok'], ['V', 'ok'], ['S', 'off'], ['D', 'off']].map(([d, s], i) => (
                      <span key={i} className={`lp-day lp-day--${s}`}>
                        <b>{s === 'ok' ? '✓' : s === 'miss' ? '!' : '–'}</b>{d}
                      </span>
                    ))}
                  </div>
                  <div className="lp-streak"><span className="lp-flame"><Icon name="flame" size={18} /></span>6 semaines à jour</div>
                </div>
              </article>

              <article className="lp-card lp-span-3">
                <span className="lp-kicker lp-kicker--sm">Progression</span>
                <h3>Votre année, en chiffres et en milestones.</h3>
                <p>Interventions, première main, consultations : suivez où vous en êtes et franchissez les étapes de votre formation.</p>
                <div className="lp-demo lp-progress" aria-hidden="true">
                  <span className="lp-ring">
                    <svg width="96" height="96" viewBox="0 0 44 44">
                      <circle cx="22" cy="22" r="18.5" fill="none" stroke="#E6ECF5" strokeWidth="6" />
                      <circle cx="22" cy="22" r="18.5" fill="none" stroke="#1D7A4C" strokeWidth="6" strokeLinecap="round" strokeDasharray="83.7 116.2" transform="rotate(-90 22 22)" />
                    </svg>
                    <span>72 %</span>
                  </span>
                  <span className="lp-progress-txt"><strong>du carnet complété</strong><small>Exemple d'une 3e année</small></span>
                </div>
              </article>

              <article className="lp-card lp-span-3">
                <span className="lp-kicker lp-kicker--sm">Export</span>
                <h3>Le carnet officiel, prêt en un clic.</h3>
                <p>Un fichier Excel avec toutes vos activités, année par année, à remettre à votre maître de stage.</p>
                <div className="lp-demo" aria-hidden="true">
                  <div className="lp-file">
                    <span className="lp-chip lp-chip--white"><Icon name="excel" size={24} /></span>
                    <span className="lp-file-txt"><strong>Carnet de stage officiel</strong><small>Excel · interventions, consultations, gardes et formations</small></span>
                    <span className="lp-btn lp-btn--primary lp-btn--xs">Exporter</span>
                  </div>
                </div>
              </article>
            </div>
          </div>
        </section>

        {/* Étapes */}
        <section className="lp-section lp-section--no-top" id="etapes">
          <div className="lp-wrap">
            <span className="lp-kicker">Comment ça marche</span>
            <h2 className="lp-h2">Trois étapes, de l'inscription au carnet rempli.</h2>
            <ol className="lp-steps">
              {steps.map((s, i) => (
                <li className="lp-step" key={s.title}>
                  <span className="lp-step-n" aria-hidden="true">{i + 1}</span>
                  <h3>{s.title}</h3>
                  <p>{s.text}</p>
                </li>
              ))}
            </ol>
          </div>
        </section>

        {/* Origine + partenaire */}
        <section className="lp-section lp-section--no-top">
          <div className="lp-wrap">
            <div className={`lp-origin ${partner ? '' : 'lp-origin--solo'}`}>
              <DecoCross className="lp-origin-cross" size={320} />
              <div className="lp-rel">
                <span className="lp-kicker">{origin.kicker}</span>
                <h2 className="lp-h2">{origin.title}</h2>
                <p>{origin.text}</p>
              </div>
              {partner && (
                <AppLink to={partner.href || cfg.partnersHref} className="lp-partner">
                  <span className="lp-partner-k">Application sponsorisée par</span>
                  {partner.logoSrc ? (
                    <img src={partner.logoSrc} alt={partner.name} className="lp-partner-logo" />
                  ) : (
                    <span className="lp-partner-name">{partner.name}</span>
                  )}
                  {partner.tagline && <span className="lp-partner-tag">{partner.tagline}</span>}
                  <span className="lp-partner-more">Découvrir nos partenaires ›</span>
                </AppLink>
              )}
            </div>
          </div>
        </section>

        {/* FAQ */}
        <section className="lp-section lp-section--no-top" id="faq">
          <div className="lp-wrap lp-faq">
            <div>
              <span className="lp-kicker">FAQ</span>
              <h2 className="lp-h2">Vos questions, nos réponses.</h2>
              {mailto && (
                <p className="lp-sub">Une autre question ? <a href={mailto}>Écrivez-nous</a>.</p>
              )}
            </div>
            <div className="lp-faq-list">
              {faq.map((q, i) => (
                <details className="lp-q" key={q.question} open={i === 0}>
                  <summary>
                    <span>{q.question}</span>
                    <span className="lp-chev" aria-hidden="true"><Icon name="chevron-down" size={18} strokeWidth={2.4} /></span>
                  </summary>
                  <div className="lp-a">
                    {q.answer.map((p) => <p key={p}>{p}</p>)}
                    {q.steps && (
                      <ol>
                        {q.steps.map(([label, text]) => (
                          <li key={label}><strong>{label}</strong> : {text}</li>
                        ))}
                      </ol>
                    )}
                  </div>
                </details>
              ))}
            </div>
          </div>
        </section>

        {/* Appel final */}
        <section>
          <div className="lp-wrap">
            <div className="lp-final">
              <DecoCross className="lp-final-cross" size={300} color="#FFFFFF" />
              <div className="lp-rel">
                <h2>{finalCta.title}</h2>
                <p>{finalCta.text}</p>
              </div>
              <div className="lp-cta-row lp-rel">
                <AppLink to={cfg.registerHref} className="lp-btn lp-btn--white lp-btn--lg">Créer mon compte</AppLink>
                <AppLink to={cfg.loginHref} className="lp-btn lp-btn--outline-white lp-btn--lg">Se connecter</AppLink>
              </div>
            </div>
          </div>
        </section>
      </main>

      <footer className="lp-foot">
        <div className="lp-wrap lp-foot-in">
          <Logo showMark={false} fontSize={18} />
          <nav className="lp-foot-links" aria-label="Pied de page">
            {mailto && <a href={mailto}>Nous contacter</a>}
            <AppLink to={cfg.termsHref}>Conditions d'utilisation</AppLink>
            <AppLink to={cfg.partnersHref}>Partenaires</AppLink>
          </nav>
          <span className="lp-version">Version {cfg.version}</span>
        </div>
      </footer>
    </div>
    </LinkCtx.Provider>
  );
}
