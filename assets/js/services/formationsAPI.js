import axios from "axios";
import { FORMATIONS_API } from "../config";

/**
 * Permet de rechercher toutes les formations de l'utilisateur.
 * @return Tableau de formation
 */
function  findAll() {
    return axios
    .get(FORMATIONS_API)  
}

/**
 * Permet de créer un évènement de formation pour une année donnée.
 */
function create(formation) {
    return axios.post(FORMATIONS_API, formation);
}

/**
 * Permet de supprimer un évènement de formation.
 * @param id Id de l'évènement.
 */
function  deleteFormation(id) {
    return axios
    .delete(FORMATIONS_API + "/" + id)  
}

/**
 * Permet de rechercher un évènement de formation par son Id.
 * @param id Id de la formation.
 * @return Un tableau de l'évènement.
 */
function  find(id) {
    return axios
    .get(FORMATIONS_API + "/" + id)  
    .then(response => response.data);
}


/**
 * Permet de modifier un évènement de formation.
 * @param id Id de l'évènement de formation.
 * @param formation Entrées.
 */
function update(id, formation){
    return axios
    .put(FORMATIONS_API + "/" + id, formation)
}

/**
 * Recherche une page en particulier.
 */
function fetchPage(page){
    return axios
        .get(FORMATIONS_API + "?page=" + page)
        .then(response =>response.data['hydra:member']);      
}


export default {
    findAll,
    create ,
    deleteFormation,
    find,
    update, 
    fetchPage
}