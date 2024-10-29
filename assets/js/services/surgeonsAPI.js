import axios from "axios";
import { SURGEONS_API , SURGEONS_LIST_API } from "../config";

/**
 * Renvoie la liste des superviseurs par année.
 */
function findSurgeons(year) {
    return axios.get(SURGEONS_LIST_API + "/" + year);
}

/**
 * Permet de créer un superviseur pour une année donnée.
 */
function createSurgeon(surgeon) {
    return axios.post(SURGEONS_API, surgeon);
}

/**
 * Permet de supprimer un chirugien de la liste
 * @param id Id du chirugien.
 */
function  deleteSurgeon(id) {
    return axios
        .delete(SURGEONS_API + "/" + id)  
}

/**
 * Permet de rechercher un chirugien par son Id.
 * @param id Id du chirugien.
 */
function  find(id) {
    return axios
        .get(SURGEONS_API + "/" + id)  
}

/**
 * Permet de modifier un superviseur.
 * @param id Id du superviseur.
 * @param surgeon Entrées.
 */
function update(id, surgeon){
    return axios
    .put(SURGEONS_API + "/" + id, surgeon)
}

export default {
    findSurgeons,
    createSurgeon,
    deleteSurgeon,
    update,
    find
    
}