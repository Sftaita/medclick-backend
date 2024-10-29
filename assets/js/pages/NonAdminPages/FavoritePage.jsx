import React, { useEffect, useState } from 'react';
import Field from "../components/Forms/Field";
import Select from "../components/Forms/Select";
import nomenclatureAPI from "../services/nomenclatureAPI";
import Modal from "../components/Modal/Modal";
import CustomCheckbox from "../components/Forms/Checkbox/CustomCheckbox";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";
import { Link } from "react-router-dom";
import favoritesAPI from "../services/favoritesAPI";

const FavoritePage = ({history, match}) => {

    // Initialisation :

        const {id = "new"} = match.params;
        const [editing,setEditing] = useState(false);
        const [loading,setLoading] = useState(true);
        const [loadingModal,setLoadingModal] = useState(true);

        const [favorite, setFavorite] = useState({
            shortcut: "",
            SurgeryName : "",
            codeHospitalisation : "",
            speciality : "" 
        });

        const [errors, setErrors] = useState({
            shortcut: "",
            SurgeryName : "",
            codeHospitalisation : "",
            speciality : "" 
        });

    // Chargement de l'intervention si besoin au chargement du composant ou au changement de l'identifiant.
        useEffect (() => {
            if (id !== "new") {
                setEditing(true);
                fetchFavorite(id);   
            }
            else{
                setLoading(false);
            }
        }
        ,[id]);

            
            // Récupération de l'intervention selon l'ID :
                const fetchFavorite = async id => {
                    try{
                        const data = await favoritesAPI.find(id);
                        const {shortcut,  SurgeryName, speciality} = data;
                        setFavorite({ shortcut,  SurgeryName, speciality });
                        setLoading(false);  
                                                        
                    }catch(error){
                        history.replace('/favorites');
                }
    }

    //  Gestion des champs :
        const handleChange = ({currentTarget}) => {
            const value = currentTarget.value;
            const name = currentTarget.name;
            setFavorite({...favorite, [name]:value})
            closeModalHandler();
        }

        const handleChangeSpeciality = ({currentTarget}) => {
            const value = currentTarget.value;
            const name = currentTarget.name;
            setSpeciality({...speciality, [name]:value});
            setFavorite({...favorite, [name]:value})  
        }
    
    // Gestion de choix de spédcialité :
            const [speciality, setSpeciality] = useState ({
                speciality: ""
            });

            // Contient la liste de nomenclature brute.
            const [list,setList]=useState([]);

            // Gestion de l'ouverture du Modal :
            const [show,setShow] = useState(false)
            const openModalHandler = () => {
                if(speciality.speciality !== "")
                    {setShow(true)}
            };
            const closeModalHandler = () => setShow(false);

            // Lorsqu'une spécialité est sélectionner, ouverture de la fenêtre des choix et recheche des interventions de la spécialité.
            useEffect(()=> {
                if(speciality.speciality !== ""){
                setShow(true)
                fetchSpe();
                setLoadingModal(true)
                }
            },[speciality]) 

           
            //Permet de récuper l'ensemble des propositions d'intervention d'une spécialité.
            const fetchSpe = async () => {
                try{
                    const response = await nomenclatureAPI.fetch(speciality.speciality);
                    setList(response.data);
                    setLoadingModal(false)
                }
                catch(error){
                    console.log(error.response);
                }
            }

            //Gestion de la recherche.
            const [search, setSearch] = useState("");
            const handleSearch = event => {
                const value = event.currentTarget.value;
                setSearch(value);
            }

            // Contient la liste de nomenclature filtrer.
            const filteredSearch = list.filter(l => 
                l.name.toLowerCase().includes(search.toLowerCase()) || 
                l.codeAmbulant.toLowerCase().includes(search.toLowerCase()) ||  
                l.codeHospitalisation.toLowerCase().includes(search.toLowerCase()));

    
    //Gestion de la soumission du formulaire.
        const handleSubmit = async (event) => {
        event.preventDefault();
        const apiErrors = {};      
        
        try{
            if(editing) {
                const response = await favoritesAPI.update(id, favorite);
                history.replace("/favorites");
            } else {
                const response = await favoritesAPI.create(favorite);
                history.replace("/favorites");
            }
            setErrors({});

        }catch({ response }){
            const {violations} = response.data;
            if(violations){
                const apiErrors = {};
                violations.forEach(({propertyPath, message}) => {
                    apiErrors[propertyPath] = message;
                });
                setErrors(apiErrors);        
            };
        }
    } 
        


    console.log(favorite);
    
    return ( 
        <>
        <div className="form-content">
            {(!editing && <h1>Ajouter une intervention</h1>) || (<h1>Modifier une intervention</h1>)}

            {loading && <SpinnerLoader/>}

            {!loading && <form onSubmit={handleSubmit}>

                <Field name="shortcut" placeholder="Nom du favoris" label="Nom" onChange={handleChange} value={favorite.shortcut} error={errors.shortcut}></Field>

                <Select name="speciality" 
                        label="Spécialité" 
                        value={favorite.speciality} 
                        error="" 
                        onChange={handleChangeSpeciality}
                    >
                            <option value="">Choisir une spécialité</option>
                            <option value="ortho">Chirurgie orthopédique</option>
                            <option value="dig">Chirurgie digestive</option>
                            <option value="general">Chirurgie générale</option>
                            <option value="uro">Chirurgie urologique</option>
                            <option value="vasc">Chirurgie vasculaire</option>
                            <option value="thor">Chirurgie thoracique</option>
                            <option value="plastic">Chirurgie plastique</option>
                            <option value="neuro">Neurochirurgie</option>
                            <option value="transp">Transplantation</option>
                    </Select>

                    {(favorite.speciality !== "") && <Field  name="SurgeryName" label="Intervention" placeholder="Nom d'intervention" value={favorite.SurgeryName} onChange={handleChange} error={errors.SurgeryName} onClick={openModalHandler}></Field>} 
                    
                    { show ? 
                        <div className="back-drop">
                            {loadingModal && <SpinnerLoader />}
                            {!loadingModal && 
                                <Modal show={show} close={closeModalHandler} 
                                    titre="Sélectionner une intervention" 
                                    search={
                                        <div className="form-group">
                                            <input type="text" onChange={handleSearch} value={search} className="form-control" placeholder="Rechercher"/>  
                                        </div>}
                                    body={
                                        
                                        <div className="form-groupe" 
                                        >
                                                {filteredSearch.map(x => (
                                                    <div key={x.id}>
                                                    
                                                    <CustomCheckbox label= {x.name} id={x.id} name="SurgeryName" value={x.name} onChange={handleChange}/>
            
                                                    </div>
                                                ))}
                                        </div>
                                        
                                    }
                            />}
                        </div> 
                    : null}

            <div className="div form-group">
                <button type="submit" className="btn btn-success">Enregistrer</button>
                <Link to="/favorites" className="btn btn-link">Mes favoris</ Link>
            </div>
        </form>}
        </div>
    </>
     );
}
 
export default FavoritePage;