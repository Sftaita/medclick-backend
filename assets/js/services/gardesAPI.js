import axios from "axios";
import { GARDES_API } from "../config";

/**
 * Permet de rechercher toutes les gardes de l'utilisateur.
 * @return Tableau de formation
 */
function  findAll() {
    return axios
    .get(GARDES_API)  
}

/**
 * Permet de créer une garde pour une année donnée.
 */
function create(garde) {
    return axios.post(GARDES_API, garde);
}

/**
 * Permet de supprimer une garde.
 * @param id Id de la garde.
 */
function  deleteGarde(id) {
    return axios
    .delete(GARDES_API + "/" + id)  
}

/**
 * Permet de rechercher une garde par son Id.
 * @param id Id de la garde.
 * @return Un tableau de la garde.
 */
function  find(id) {
    return axios
    .get(GARDES_API + "/" + id)  
    .then(response => response.data);
}


/**
 * Permet de modifier une garde.
 * @param id Id de la garde.
 * @param garde Entrées.
 */
function update(id, garde){
    return axios
    .put(GARDES_API + "/" + id, garde)
}

/**
 * Recherche une page en particulier.
 */
function fetchPage(page){
    return axios
        .get(GARDES_API + "?page=" + page)
        .then(response =>response.data['hydra:member']);      
}


export default {
    findAll,
    create ,
    deleteGarde,
    find,
    update,
    fetchPage 
}