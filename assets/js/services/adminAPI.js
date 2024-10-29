import axios from "axios";
import { ADMIN_API } from "../config";

/**
 * Renvoie la liste des nomclature par specialité.
 * @return Array
 */
function fetchNomenclature(speciality) {
  return axios
    .get(ADMIN_API + "/nomenclature/" + speciality)
    .then((response) => response.data);
}

/**
 * Met à jour une nomenclature.
 * @param {Object} speciality - L'objet contenant les données de la nomenclature à mettre à jour.
 * @return {Promise<Array>} - Une promesse qui résout les données de la réponse.
 */
function updateNomenclature(speciality) {
  return axios
    .put(ADMIN_API + "/nomenclature", speciality)
    .then((response) => response.data);
}

/**
 * Crée une nomenclature.
 * @param {Object} speciality -
 * @return {Promise<Array>} - Une promesse qui résout les données de la réponse.
 */
function createNomenclature(speciality) {
  return axios
    .post(ADMIN_API + "/nomenclature", speciality)
    .then((response) => response.data);
}

export default {
  createNomenclature,
  fetchNomenclature,
  updateNomenclature,
};
