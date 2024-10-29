import axios from "axios";
import { jwtDecode } from "jwt-decode";
import { LOGGIN_API } from "../config";

/**
 * Requête HTTP d'authentification et stockage dans le storage et sur Axios
 * @param {object} credentials
 */
function authenticate(credentials) {
  return axios
    .post(LOGGIN_API, credentials)
    .then((response) => response.data.token)
    .then((token) => {
      // Stockage du token dans le local storage.
      window.localStorage.setItem("authToken", token);

      // Signal à axios qu'un header précède toutes les requêtes HTTP
      setAxiosToken(token);
    });
}

/**
 * Déconnexion (suppression du token du localStorage et sur Axios)
 */
function logout() {
  window.localStorage.removeItem("authToken");
  delete axios.defaults.headers["Authorization"];
}

/**
 * Positionne le token JWT sur Axios
 * @param {string} token Le token JWT
 */
function setAxiosToken(token) {
  axios.defaults.headers["Authorization"] = "Bearer " + token;
}

/**
 * Mise en place lors du chargement de l'application
 */
function setup() {
  const token = window.localStorage.getItem("authToken");

  if (token) {
    const { exp: expiration } = jwtDecode(token);
    if (expiration * 1000 > new Date().getTime()) {
      setAxiosToken(token);
    }
  }
}

/**
 * Récupère le rôle dans le token.
 */
function getRole() {
  const token = window.localStorage.getItem("authToken");

  if (token) {
    const { roles } = jwtDecode(token);
    return roles.includes("ROLE_ADMIN") ? "ROLE_ADMIN" : "ROLE_USER";
  }
  return null;
}

/**
 * Récupère le nom et prénom de l'utilisateur pour le stocker dans UserName.
 * @var string
 */
function getIdentity() {
  const token = window.localStorage.getItem("authToken");

  if (token) {
    const { firstname, lastname } = jwtDecode(token);
    return { firstname, lastname };
  }
  return null;
}

/**
 * Permet de déterminer si l'utilisateur est connecté.
 * @return boolean
 */
function isAuthenticated() {
  const token = window.localStorage.getItem("authToken");

  if (token) {
    const { exp: expiration } = jwtDecode(token);
    return expiration * 1000 > new Date().getTime();
  }
  return false;
}

export default {
  authenticate,
  logout,
  setup,
  isAuthenticated,
  getRole,
  getIdentity,
};
