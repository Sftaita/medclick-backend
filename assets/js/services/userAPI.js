import axios from "axios";
import { USERS_API, USERS_LIST_API , USERS_STAT_API} from "../config";

/**
 * Permet d'enregistrer un nouvel utilisateur.
 */
function create(user) {
    return axios.post(USERS_API, user);
}

/**
 * 
 * @param {int} id 
 * @param {array} user 
 */
function update(id, user){
    return axios.put(USERS_API + "/" + id, user);
}

/**
 * Récupère les informations des utilisateurs.
 */
function fetch() {
    return axios
    .get(USERS_API)
    .then(response => response.data['hydra:member']);
    
}

/**
 * Récupère la liste des utilisateurs.
 * Limité à l'utilisateur si ROLE_USER. Si ROLE_ADMIN, accès à tous les users.
 */
function getUsersList() {
    return axios
    .get(USERS_LIST_API)
    .then(response => response.data);  
}


/**
 * Permet de récupérer des informations sur l'utilisateur.
 */
function getInfo() {
    return axios
    .get(USERS_API)
    .then(response => response.data['hydra:member']);
}

/**
 * Permet de récupérer des statistiques sur l'utilisateur.
 */
function getStat(id) {
    return axios
    .get(USERS_STAT_API + "/" + id)
    .then(response => response.data);
}

export default {
    create,
    getInfo,
    fetch,
    getUsersList,
    update,
    getStat
    
}