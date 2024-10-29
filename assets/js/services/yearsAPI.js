import axios from "axios";
import { YEARS_API } from "../config";

/**
 * Recherche toutes les années de formation de l'utilisateur.
 */
function findAll() {
  return axios.get(YEARS_API).then((response) => response.data["hydra:member"]);
}

/**
 * Permet de créer une nouvelle année de formation.
 */
function create(year) {
  return axios.post(YEARS_API, year);
}

/**
 * Permet de rechercher une année en fonction de l'id.
 */
function find(id) {
  return axios.get(YEARS_API + "/" + id);
}

/**
 * Permet de modifier une année.
 */
function update(id, year) {
  return axios.put(YEARS_API + "/" + id, year);
}

/**
 * @param id de l'année désirée.
 * Permet de télécharger l'excel de manière authentifié.
 */
function excel(id, callback) {
  return axios({
    url: "http://127.0.0.1:8000/api/excel/" + id,
    //url: 'https://www.medclick.be/api/excel/' + id,
    method: "GET",
    responseType: "blob", // important
    headers: { Accept: "application/vnd.ms-excel" },
  }).then((response) => {
    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement("a");
    link.href = url;
    link.setAttribute("download", `Relevé.xlsx`);
    document.body.appendChild(link);
    link.click();
    callback();
  });
}

export default {
  findAll,
  create,
  find,
  update,
  excel,
};
