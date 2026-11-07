// Aperçu : nomenclature ACTUELLE lue dans la base locale à chaque chargement (public/nomenclature.php).
// Paramètre d'URL `api` : normal | lent | erreur — pour voir les états de chargement et d'erreur.

// Libellés et ordre repris de frontend/src/shared/constants.js (SPECIALITIES).
const LABELS = [
  ['ortho', 'Chirurgie orthopédique'],
  ['dig', 'Chirurgie digestive'],
  ['general', 'Chirurgie générale'],
  ['uro', 'Chirurgie urologique'],
  ['vasc', 'Chirurgie vasculaire'],
  ['thor', 'Chirurgie thoracique'],
  ['plastic', 'Chirurgie plastique'],
  ['neuro', 'Neurochirurgie'],
  ['transp', 'Transplantation'],
];

const mode = new URLSearchParams(location.search).get('api') || 'normal';
const delay = mode === 'lent' ? 2500 : 250;
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

let rows = null;
async function load() {
  if (!rows) {
    const data = await (await fetch('nomenclature.php')).json();
    rows = data.map(([name, codeAmbulant, codeHospitalisation, n, speciality], i) => ({
      id: i, name, codeAmbulant, codeHospitalisation, n, speciality,
    }));
  }
  return rows;
}

export const realApi = {
  async getSpecialities() {
    const all = await load();
    await wait(Math.min(delay, 300));
    const present = new Set(all.map((r) => r.speciality));
    return LABELS.filter(([id]) => present.has(id)).map(([id, label]) => ({ id, label }));
  },
  async getCodes(speciality) {
    const all = await load();
    await wait(delay);
    if (mode === 'erreur') throw new Error('HTTP 503');
    return speciality === 'all' ? all : all.filter((r) => r.speciality === speciality);
  },
};
