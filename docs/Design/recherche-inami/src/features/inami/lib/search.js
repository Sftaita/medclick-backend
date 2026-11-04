/**
 * Fonctions pures de la recherche INAMI (aucune dépendance à React).
 * Modèle attendu d'un code : { name, codeAmbulant, codeHospitalisation, n, speciality }
 */

/** Minuscules, sans accents, apostrophes typographiques remplacées par un espace. */
export function normalize(value) {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[’'’]/g, ' ')
    .toLowerCase();
}

/** Ne garde que les chiffres d'un code (« 289-414 », « 289 414 » → « 289414 »). */
export function digitsOnly(value) {
  return String(value ?? '').replace(/\D/g, '');
}

/**
 * Lit la valeur N/K, quel que soit son format en base :
 * « K 225 », « K225 », « k 225 », 225, « N15 », « 1,5 »…
 * → { letter: 'K' | 'N' | null, value: '225' | null, raw }
 * À ajuster une fois le format réel du champ `n` vérifié.
 */
export function parseNK(raw) {
  if (raw === null || raw === undefined || raw === '') return { letter: null, value: null, raw: '' };
  const text = String(raw).trim();
  const m = text.match(/^([NKnk])\s*([\d.,]+)$/);
  if (m) return { letter: m[1].toUpperCase(), value: m[2], raw: text };
  if (/^[\d.,]+$/.test(text)) return { letter: null, value: text, raw: text };
  return { letter: null, value: text, raw: text };
}

function nkSortValue(nk) {
  const num = parseFloat(String(nk.value ?? '').replace(',', '.'));
  return Number.isFinite(num) ? num : -1;
}

/**
 * Prépare une liste pour la recherche : calcule une fois pour toutes les versions
 * normalisées (appelé dans un useMemo, pas à chaque frappe).
 */
export function buildIndex(codes) {
  return codes.map((c, i) => ({
    id: c.id ?? `${c.codeHospitalisation || ''}-${c.codeAmbulant || ''}-${i}`,
    name: c.name ?? '',
    codeAmbulant: c.codeAmbulant ? String(c.codeAmbulant) : '',
    codeHospitalisation: c.codeHospitalisation ? String(c.codeHospitalisation) : '',
    speciality: c.speciality ?? '',
    nk: parseNK(c.n),
    _name: normalize(c.name),
    _a: digitsOnly(c.codeAmbulant),
    _h: digitsOnly(c.codeHospitalisation),
  }));
}

/**
 * Filtre l'index.
 * - texte : cherché dans le nom (sans accents ni casse), chaque mot doit être présent ;
 * - chiffres (≥ 2) : cherchés dans les codes ambulatoire et hospitalisation.
 */
export function filterIndex(index, query) {
  const q = normalize(query).trim();
  if (!q) return index;
  const onlyDigits = /^[\d\s.-]+$/.test(q);
  const qd = digitsOnly(q);
  const words = q.split(/\s+/).filter(Boolean);
  return index.filter((r) => {
    if (onlyDigits && qd.length >= 2) return r._a.includes(qd) || r._h.includes(qd);
    return words.every((w) => r._name.includes(w));
  });
}

/** Tri : 'relevance' (le nom commence par la recherche d'abord), 'name' ou 'nk'. */
export function sortRows(rows, { key = 'name', dir = 1, query = '' } = {}) {
  const q = normalize(query).trim();
  const out = rows.slice();
  out.sort((a, b) => {
    if (key === 'nk') {
      const la = a.nk.letter || '', lb = b.nk.letter || '';
      if (la !== lb) return (la < lb ? -1 : 1) * dir;
      return (nkSortValue(a.nk) - nkSortValue(b.nk)) * dir;
    }
    if (key === 'relevance' && q) {
      const sa = a._name.startsWith(q) ? 0 : 1, sb = b._name.startsWith(q) ? 0 : 1;
      if (sa !== sb) return sa - sb;
    }
    return a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }) * dir;
  });
  return out;
}

/**
 * Découpe un libellé en morceaux surlignés ou non, sans tenir compte des accents.
 * → [{ text, hit }]
 */
export function highlightParts(name, query) {
  const words = normalize(query).trim().split(/\s+/).filter((w) => w && !/^\d+$/.test(w));
  if (!words.length) return [{ text: name, hit: false }];
  // normalisation caractère par caractère pour garder les positions
  const chars = Array.from(name);
  const norm = chars.map((c) => { const n = normalize(c); return n.length === 1 ? n : c.toLowerCase(); }).join('');
  if (norm.length !== chars.length) return [{ text: name, hit: false }];
  const mask = new Array(chars.length).fill(false);
  words.forEach((w) => {
    let i = 0, idx;
    while ((idx = norm.indexOf(w, i)) !== -1) { for (let k = idx; k < idx + w.length; k++) mask[k] = true; i = idx + w.length; }
  });
  const parts = [];
  chars.forEach((c, i) => {
    const last = parts[parts.length - 1];
    if (last && last.hit === mask[i]) last.text += c; else parts.push({ text: c, hit: mask[i] });
  });
  return parts;
}
