import { Icon } from './Icons';
import { highlightParts } from '../lib/search';

function Highlight({ text, query }) {
  return highlightParts(text, query).map((p, i) => (p.hit ? <mark key={i}>{p.text}</mark> : <span key={i}>{p.text}</span>));
}

/** Recherche par numéro : la partie trouvée du code est surlignée (ajout de l'aperçu). */
function CodeText({ code, query }) {
  const q = /^[\d\s.-]+$/.test(query || '') ? query.replace(/\D/g, '') : '';
  const i = q.length >= 2 ? code.indexOf(q) : -1;
  if (i < 0) return code;
  return <>{code.slice(0, i)}<span className="ia-code-hit">{code.slice(i, i + q.length)}</span>{code.slice(i + q.length)}</>;
}

function NK({ nk }) {
  if (!nk.value) return <span className="ia-none">—</span>;
  const label = nk.letter ? `Coefficient ${nk.letter} ${nk.value}` : `Valeur ${nk.value}`;
  return (
    <span className={`ia-nk ${nk.letter === 'N' ? 'ia-nk--n' : ''}`} title={label} aria-label={label}>
      {nk.letter && <b aria-hidden="true">{nk.letter}</b>}
      <span aria-hidden="true">{nk.value}</span>
    </span>
  );
}

function CodeCell({ code, kind, copiedKey, rowId, onCopy, query }) {
  if (!code) return <span className="ia-none" title={`Pas de code ${kind}`}>—<span className="ia-sr">aucun code {kind}</span></span>;
  const key = `${rowId}-${kind}`;
  const done = copiedKey === key;
  return (
    <span className="ia-codecell">
      <span className="ia-code"><CodeText code={code} query={query} /></span>
      <button type="button" className={`ia-copy ${done ? 'ia-copy--done' : ''}`} aria-label={`Copier le code ${kind} ${code}`} onClick={() => onCopy(code, key)}>
        {done ? <><Icon name="check" size={16} strokeWidth={2.8} />Copié</> : <Icon name="copy" size={17} />}
      </button>
    </span>
  );
}

function CodeBox({ code, kind, label, copiedKey, rowId, onCopy, query }) {
  const key = `${rowId}-${kind}`;
  const done = copiedKey === key;
  return (
    <button
      type="button"
      className={`ia-cbox ${done ? 'ia-cbox--done' : ''}`}
      disabled={!code}
      aria-label={code ? `Copier le code ${kind} ${code}` : `Pas de code ${kind}`}
      onClick={() => code && onCopy(code, key)}
    >
      <span className="ia-cbox-t"><small>{label}</small><span>{code ? <CodeText code={code} query={query} /> : '—'}</span></span>
      {code && <span className="ia-cbox-i">{done ? <Icon name="check" size={18} strokeWidth={2.8} /> : <Icon name="copy" size={18} />}</span>}
    </button>
  );
}

function SortHeader({ label, active, dir, onClick, style }) {
  return (
    <th scope="col" style={style} aria-sort={active ? (dir === 1 ? 'ascending' : 'descending') : 'none'}>
      <button type="button" className="ia-th-btn" onClick={onClick}>
        {label}
        <span className={`ia-arrow ${active ? 'ia-arrow--on' : ''} ${active && dir === -1 ? 'ia-arrow--down' : ''}`}>
          <Icon name="arrow-up" size={14} strokeWidth={2.6} />
        </span>
      </button>
    </th>
  );
}

/**
 * Résultats : tableau (≥ 761 px de large) ou cartes (smartphone), même contenu.
 * animKey change à chaque nouvelle recherche pour rejouer l'apparition en cascade.
 */
export default function Results({ rows, query, showSpeciality, specialityLabel, sort, onSort, copiedKey, onCopy, animKey }) {
  const nameActive = sort.key === 'name' || sort.key === 'relevance';
  return (
    <div className={`ia-res ${animKey % 2 ? 'ia-res--b' : 'ia-res--a'}`}>
      <div className="ia-tablebox">
        <table className="ia-table">
          <caption className="ia-sr">Codes INAMI</caption>
          <thead>
            <tr>
              <SortHeader label="Nom" active={nameActive} dir={sort.dir} onClick={() => onSort('name')} />
              <th scope="col" style={{ width: 210 }}>Code ambulatoire</th>
              <th scope="col" style={{ width: 210 }}>Code hospitalisation</th>
              <SortHeader label="N ou K" active={sort.key === 'nk'} dir={sort.dir} onClick={() => onSort('nk')} style={{ width: 130 }} />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                <td>
                  <span className="ia-name"><Highlight text={r.name} query={query} /></span>
                  {showSpeciality && <span className="ia-spec">{specialityLabel(r.speciality)}</span>}
                </td>
                <td><CodeCell code={r.codeAmbulant} kind="ambulatoire" rowId={r.id} copiedKey={copiedKey} onCopy={onCopy} query={query} /></td>
                <td><CodeCell code={r.codeHospitalisation} kind="hospitalisation" rowId={r.id} copiedKey={copiedKey} onCopy={onCopy} query={query} /></td>
                <td><NK nk={r.nk} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <ul className="ia-cards">
        {rows.map((r) => (
          <li className="ia-card" key={r.id}>
            <div className="ia-card-top">
              <h3 className="ia-card-name">
                <Highlight text={r.name} query={query} />
                {showSpeciality && <span className="ia-spec">{specialityLabel(r.speciality)}</span>}
              </h3>
              <NK nk={r.nk} />
            </div>
            <div className="ia-cboxes">
              <CodeBox code={r.codeAmbulant} kind="ambulatoire" label="Ambulatoire" rowId={r.id} copiedKey={copiedKey} onCopy={onCopy} query={query} />
              <CodeBox code={r.codeHospitalisation} kind="hospitalisation" label="Hospitalisation" rowId={r.id} copiedKey={copiedKey} onCopy={onCopy} query={query} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function SkeletonRows({ count = 6 }) {
  return (
    <div className="ia-tablebox ia-skel" aria-hidden="true">
      {Array.from({ length: count }).map((_, i) => (
        <div className="ia-skel-row" key={i}>
          <span className="ia-sk" style={{ width: `${55 + ((i * 17) % 35)}%` }} />
          <span className="ia-sk" /><span className="ia-sk" /><span className="ia-sk ia-sk--s" />
        </div>
      ))}
    </div>
  );
}
