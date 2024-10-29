import axios from "axios";
import { FAVORITES_API } from "../config";

/**
 * Renvoie la liste des favoris de l'utilisateur.
 * @return Array de favoris
 */
function fetchAll() {
    return axios.get(FAVORITES_API)
    .then(response => response.data['hydra:member']);
}


/**
 * Crée un nouveau favori.
 * @return Array de favoris
 */
function create(favorite){
    return axios.post(FAVORITES_API, favorite);
}

/**
 * Permet de supprimer un favori.
 * @param id id du favori.
 */
function  deleteFavorite(id) {
    return axios
        .delete(FAVORITES_API + "/" + id)  
}


/**
 * Recherche un favori.
 * @param id id du favori.
 */
function find(id){
    return axios
    .get(FAVORITES_API + "/" + id)
    .then(response =>response.data);
}

/**
 * Modifie un favori.
 * @param id id du favori.
 * @param favorite Les champs à modifier.
 */
function update(id, favorite){
    return axios.put(FAVORITES_API + "/" + id, favorite);
}



export default {
    fetchAll,   
    create,
    deleteFavorite,
    find,
    update
}