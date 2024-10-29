import axios from "axios";
import { NOMENCLATURE_API } from "../config";

/**
 * Renvoie la liste des intervention par spécialité.
 * @param specility Spécialité rechercher
 */
function fetch(speciality) {
    return axios.get(NOMENCLATURE_API + "/" + speciality);
}



export default {
    fetch,   
}