import axios from 'axios';
import { SURGERIES_API } from "../config";
import Cache from "./cache.js"


/**
 * Recherche toute les interventions de l'utilisateur.
 */
function findAll(){
    return axios
        .get(SURGERIES_API)
        
}

/**
 * Recherche une page en particulier.
 */
function fetchPage(page){
    return axios
        .get(SURGERIES_API + "?page=" + page)
        .then(response =>response.data['hydra:member']);      
}

/**
 * Permet de supprimer une intervention.
 */
function  deleteSurgery(id) {
    return axios
        .delete(SURGERIES_API + "/" + id) 
}

function find(id){
    return axios
    .get(SURGERIES_API + "/" + id)
    .then(response =>response.data);
}

function update(id, surgery){
    return axios.put(SURGERIES_API + "/" + id, surgery);
}

function create(surgery){
    return axios.post(SURGERIES_API, surgery);
}



export default {
    findAll,
    deleteSurgery,
    find,
    update,
    create,
    fetchPage
}