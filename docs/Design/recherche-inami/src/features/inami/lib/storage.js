/**
 * Mémorisation côté navigateur — aucune donnée personnelle.
 * - spécialité choisie : cookie (12 mois), contient uniquement l'identifiant de la spécialité ;
 * - confirmation « professionnel de la santé » : cookie de session (sans durée), partagé par
 *   tous les onglets et effacé à la fermeture du navigateur ; la fenêtre n'est donc pas
 *   redemandée dans un nouvel onglet ni en revenant sur la page pendant la même session.
 */

export const SPECIALITY_COOKIE = 'mc_inami_specialite';
export const PRO_COOKIE = 'mc_inami_pro';
const ONE_YEAR = 60 * 60 * 24 * 365;

export function readSpecialityCookie() {
  if (typeof document === 'undefined') return null;
  const m = document.cookie.match(new RegExp('(?:^|; )' + SPECIALITY_COOKIE + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : null;
}

export function writeSpecialityCookie(id) {
  if (typeof document === 'undefined') return;
  const secure = typeof location !== 'undefined' && location.protocol === 'https:' ? '; Secure' : '';
  if (!id) {
    document.cookie = `${SPECIALITY_COOKIE}=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
    return;
  }
  document.cookie = `${SPECIALITY_COOKIE}=${encodeURIComponent(id)}; Max-Age=${ONE_YEAR}; Path=/; SameSite=Lax${secure}`;
}

function secureFlag() {
  return typeof location !== 'undefined' && location.protocol === 'https:' ? '; Secure' : '';
}

export function hasConfirmedPro() {
  if (typeof document === 'undefined') return false;
  return new RegExp('(?:^|; )' + PRO_COOKIE + '=1(?:;|$)').test(document.cookie);
}

export function rememberConfirmedPro() {
  if (typeof document === 'undefined') return;
  // Pas de Max-Age : cookie de session.
  document.cookie = `${PRO_COOKIE}=1; Path=/; SameSite=Lax${secureFlag()}`;
}
