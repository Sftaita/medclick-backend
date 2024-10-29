import React from 'react';
import axios from "axios";
import { CONSULTATION_API } from "../config";

/**
 * Permet de récupérer toute les consultations d'un utilisateur.
 */
function findAll(){
    return axios
    .get(CONSULTATION_API)
}

/**
 * Permet de supprimer une consultation.
 * @Param id
 */
function deleteConsultation(id){
    return axios
    .delete( CONSULTATION_API + "/" + id)
}

/**
 * Permet de créer une consultation.
 * @Param consultation
 */
function createConsultation(consultation){
    return axios.post(CONSULTATION_API, consultation);
}

/**
 * Permet de recherche une consultation à partir de l'ID.
 * @param Id
 */
function find(id){
    return axios
    .get(CONSULTATION_API + "/" + id)
    .then(response => response.data);
}

/**
 * Permet de modifier une consultation.
 * @param id id de la consultation.
 * @param consultation date,number et year.
 */
function update(id, consultation){
    return axios
    .put(CONSULTATION_API + "/" + id, consultation)
}

/**
 * Recherche une page en particulier.
 */
function fetchPage(page){
    return axios
        .get(CONSULTATION_API + "?page=" + page)
        .then(response =>response.data['hydra:member']);      
}

export default {
    findAll,
    deleteConsultation,
    createConsultation,
    find,
    update,
    fetchPage
}