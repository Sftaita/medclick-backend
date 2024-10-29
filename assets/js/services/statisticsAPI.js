import axios from "axios";
import { STATISTICS_API, HISTORY_API } from "../config";
import Cache from "./cache.js";

/**
 * Permet de mettre à jour les statistiques d'un utitlisateur.
 */
function update(id) {
  return axios.post(STATISTICS_API + "/update/" + id);
}

/**
 * Renvoie les statistiques de l'utilisateur.
 */
async function getStats() {
  return axios
    .get(STATISTICS_API)
    .then((response) => response.data["hydra:member"][0]);
}

/**
 * Renvoie l'historique des dates de connection selon l'intervalle.
 */
async function getHistory($interval) {
  return axios
    .get(HISTORY_API + "/history/" + $interval)
    .then((response) => response.data);
}

export default {
  update,
  getStats,
  getHistory,
};
