import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import './inami.css';
import { Icon, MedClickLogo } from './components/Icons';
import AdBanner from './components/AdBanner';
import ProConfirmModal from './components/ProConfirmModal';
import Results, { SkeletonRows } from './components/Results';
import { buildIndex, filterIndex, sortRows } from './lib/search';
import { copyText } from './lib/clipboard';
import { hasConfirmedPro, readSpecialityCookie, rememberConfirmedPro, writeSpecialityCookie } from './lib/storage';
import { useAsync, useDebouncedValue } from './hooks';
import { sampleApi } from './sampleApi';

const ALL = 'all';

/**
 * Page publique « Rechercher un code INAMI ».
 *
 * Props (toutes optionnelles) :
 * - api                 : { getSpecialities(), getCodes(specialityId | 'all') } — défaut : données d'exemple
 * - campaign            : campagne active { desktopSrc, mobileSrc, href, alt, background } ou null
 * - links               : { home, login, register, terms }
 * - LinkComponent       : Link du routeur (reçoit `to`) ; défaut <a href>
 * - pageSize            : nombre de codes par paquet (défaut 25)
 * - requireConfirmation : affiche la confirmation si le visiteur arrive directement (défaut true)
 * - onDecline           : « Non » sur la confirmation (défaut : retour à la page précédente)
 */
export default function InamiSearchPage({
  api = sampleApi,
  campaign = null,
  links = {},
  LinkComponent = null,
  pageSize = 25,
  requireConfirmation = true,
  onDecline,
}) {
  const L = useMemo(() => {
    const C = LinkComponent;
    return function AppLink({ to, children, ...rest }) {
      return C ? <C to={to} {...rest}>{children}</C> : <a href={to} {...rest}>{children}</a>;
    };
  }, [LinkComponent]);
  const href = { home: '/', login: '/login', register: '/register', terms: '/conditions-utilisation', cookies: '/conditions-utilisation#cookies', ...links };

  /* --- confirmation « professionnel de la santé » --- */
  const [gate, setGate] = useState(() => requireConfirmation && !hasConfirmedPro());

  /* --- spécialités --- */
  const specs = useAsync(() => api.getSpecialities(), [api]);
  const [spec, setSpec] = useState(() => readSpecialityCookie());
  const specList = specs.data || [];
  const specLabel = useCallback((id) => specList.find((s) => s.id === id)?.label ?? id, [specList]);

  // spécialité mémorisée qui n'existe plus → comportement initial
  useEffect(() => {
    if (!specs.data || !spec || spec === ALL) return;
    if (!specs.data.some((s) => s.id === spec)) { setSpec(null); writeSpecialityCookie(null); }
  }, [specs.data, spec]);

  const chooseSpec = (id, { focus = false } = {}) => {
    setSpec(id || null);
    writeSpecialityCookie(id && id !== ALL ? id : null);
    setLimit(pageSize);
    bump();
    // Choix par raccourci : la frappe est l'étape suivante (clavier ouvert sur smartphone).
    if (focus) setTimeout(() => inputRef.current?.focus(), 0);
  };

  /* --- codes --- */
  const codes = useAsync(() => (spec ? api.getCodes(spec) : Promise.resolve([])), [api, spec]);
  const index = useMemo(() => buildIndex(codes.data || []), [codes.data]);

  /* --- recherche, tri, pagination --- */
  const [query, setQuery] = useState('');
  const debounced = useDebouncedValue(query, 120);
  const typing = query !== debounced;
  const [sort, setSort] = useState({ key: 'relevance', dir: 1 });
  const [limit, setLimit] = useState(pageSize);
  const [animKey, setAnimKey] = useState(0);
  const bump = () => setAnimKey((k) => k + 1);

  useEffect(() => { setLimit(pageSize); bump(); }, [debounced, pageSize]);

  const filtered = useMemo(() => sortRows(filterIndex(index, debounced), { ...sort, query: debounced }), [index, debounced, sort]);
  const shown = filtered.slice(0, limit);

  // Paquet suivant chargé automatiquement en approchant du bas (le bouton reste disponible).
  const moreRef = useRef(null);
  const hasMore = filtered.length > shown.length;
  useEffect(() => {
    const el = moreRef.current;
    if (!el || !hasMore || typeof IntersectionObserver === 'undefined') return undefined;
    const io = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) setLimit((l) => l + pageSize);
    }, { rootMargin: '400px 0px' });
    io.observe(el);
    return () => io.disconnect();
  }, [hasMore, pageSize, shown.length]);

  const onSort = (key) => {
    setSort((s) => {
      const same = key === 'name' ? s.key === 'name' || s.key === 'relevance' : s.key === key;
      return same ? { key, dir: -s.dir } : { key, dir: 1 };
    });
    bump();
  };

  /* --- copie --- */
  const [copiedKey, setCopiedKey] = useState(null);
  const [live, setLive] = useState('');
  const timer = useRef(null);
  useEffect(() => () => clearTimeout(timer.current), []);
  const onCopy = async (code, key) => {
    const ok = await copyText(code);
    setCopiedKey(key);
    setLive(ok ? `Code ${code} copié` : `Copie impossible, sélectionnez le code ${code}`);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setCopiedKey(null), 1600);
  };

  const inputRef = useRef(null);
  const clear = () => { setQuery(''); inputRef.current?.focus(); };

  /* --- textes d'état --- */
  const specName = spec === ALL ? 'toutes spécialités' : specLabel(spec);
  const count = debounced.trim()
    ? `${filtered.length} ${filtered.length > 1 ? 'résultats' : 'résultat'} pour « ${debounced.trim()} »`
    : `${filtered.length} codes`;

  const firstLoad = codes.loading && !codes.data;

  return (
    <div className="ia">
      <header className="ia-top">
        <div className="ia-wrap ia-top-in">
          <L to={href.home} className="ia-brand" aria-label="MedClick — accueil"><MedClickLogo /></L>
          <span className="ia-tag"><Icon name="search" size={14} strokeWidth={2.4} />Codes INAMI</span>
          <span className="ia-top-sp" />
          <L to={href.login} className="ia-link">Se connecter</L>
          <L to={href.register} className="ia-cta">S'enregistrer</L>
        </div>
      </header>

      <main className="ia-wrap">
        <AdBanner campaign={campaign} houseHref={href.home} LinkComponent={LinkComponent} />

        <div className="ia-head">
          <h1 className="ia-h1">Rechercher un code INAMI</h1>
          <p className="ia-sub">Gratuit et sans compte : retrouvez les codes de nomenclature INAMI par spécialité, intervention ou numéro de code.</p>
        </div>

        <form className="ia-panel" role="search" aria-label="Recherche de codes INAMI" onSubmit={(e) => { e.preventDefault(); inputRef.current?.blur(); }}>
          <label className="ia-field">
            <span className="ia-label">Spécialité</span>
            <span className="ia-select-wrap">
              <select className="ia-select" value={spec ?? ''} onChange={(e) => chooseSpec(e.target.value)} disabled={specs.loading && !specs.data}>
                <option value="" disabled>Choisissez votre spécialité</option>
                {specList.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                {specList.length > 0 && <option value={ALL}>Toutes les spécialités</option>}
              </select>
              <Icon name="chevron-down" size={18} strokeWidth={2.2} />
            </span>
          </label>
          <div className="ia-field">
            <label className="ia-label ia-label--q" htmlFor="ia-q">Intervention ou code</label>
            <div className={`ia-search ${!spec ? 'is-disabled' : ''}`}>
              <Icon name="search" size={20} strokeWidth={2.2} />
              <input
                ref={inputRef}
                id="ia-q"
                type="search"
                inputMode="search"
                autoComplete="off"
                spellCheck={false}
                enterKeyHint="search"
                placeholder={spec ? 'Rechercher une intervention ou un code INAMI…' : "Choisissez d'abord une spécialité"}
                value={query}
                disabled={!spec}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Escape' && query) { e.preventDefault(); clear(); } }}
              />
              {(typing || (codes.loading && codes.data)) && <span className="ia-spin" role="status" aria-label="Recherche en cours" />}
              {query && (
                <button type="button" className="ia-clear" aria-label="Effacer la recherche" onClick={clear}>
                  <Icon name="close" size={16} strokeWidth={2.6} />
                </button>
              )}
            </div>
          </div>
        </form>

        {/* Premier accès : pas encore de spécialité */}
        {!spec && (
          <section className="ia-first" aria-labelledby="ia-first-t">
            <h2 id="ia-first-t">Choisissez votre spécialité pour commencer</h2>
            <p>Elle sera retenue sur cet appareil pendant 12 mois, sans aucune donnée personnelle. <L to={href.cookies} className="ia-inline">En savoir plus</L></p>
            <div className="ia-chips">
              {specs.loading && !specs.data
                ? Array.from({ length: 5 }).map((_, i) => <span key={i} className="ia-sk ia-sk--chip" aria-hidden="true" />)
                : specList.map((s) => <button type="button" key={s.id} className="ia-chip" onClick={() => chooseSpec(s.id, { focus: true })}>{s.label}</button>)}
            </div>
          </section>
        )}

        {spec && (
          <>
            <div className="ia-meta">
              <span className="ia-count" aria-live="polite">
                {firstLoad ? 'Chargement des codes…' : <>{count} <span>· {specName}</span></>}
              </span>
              <span className="ia-hint">Cliquez sur un code pour le copier</span>
            </div>

            {firstLoad && <SkeletonRows />}

            {codes.error && (
              <div className="ia-empty" role="alert">
                <span className="ia-empty-ic ia-empty-ic--err"><Icon name="alert" size={24} strokeWidth={2.2} /></span>
                <h2>Impossible de charger les codes</h2>
                <p>Vérifiez votre connexion puis réessayez.</p>
                <button type="button" onClick={codes.retry}>Réessayer</button>
              </div>
            )}

            {!firstLoad && !codes.error && filtered.length === 0 && (
              <div className="ia-empty">
                <span className="ia-empty-ic"><Icon name="search-minus" size={26} strokeWidth={2.2} /></span>
                <h2>{debounced.trim() ? `Aucun code pour « ${debounced.trim()} »` : 'Aucun code dans cette spécialité'}</h2>
                <p>Vérifiez l'orthographe, essayez un mot plus court ou un numéro de code.</p>
                {spec !== ALL && <button type="button" onClick={() => chooseSpec(ALL)}>Chercher dans toutes les spécialités</button>}
              </div>
            )}

            {!firstLoad && !codes.error && filtered.length > 0 && (
              <>
                <Results
                  rows={shown}
                  query={debounced}
                  showSpeciality={spec === ALL}
                  specialityLabel={specLabel}
                  sort={sort}
                  onSort={onSort}
                  copiedKey={copiedKey}
                  onCopy={onCopy}
                  animKey={animKey}
                />
                {hasMore && (
                  <div className="ia-more" ref={moreRef}>
                    <button type="button" onClick={() => setLimit((l) => l + pageSize)}>
                      Afficher {Math.min(pageSize, filtered.length - shown.length)} codes de plus
                    </button>
                    <span>{shown.length} affichés sur {filtered.length}</span>
                  </div>
                )}
              </>
            )}
          </>
        )}

        <span className="ia-sr" aria-live="polite">{live}</span>

        <footer className="ia-foot">
          <p>Codes issus de la nomenclature utilisée dans MedClick, à titre indicatif. La nomenclature officielle de l'INAMI fait foi.</p>
          <L to={href.terms}>Conditions d'utilisation</L>
          <L to={href.cookies}>Cookies</L>
          <L to={href.home}>Découvrir MedClick</L>
        </footer>
      </main>

      <ProConfirmModal
        open={gate}
        onConfirm={() => { rememberConfirmedPro(); setGate(false); }}
        onDecline={() => { if (onDecline) onDecline(); else window.history.back(); }}
      />
    </div>
  );
}
