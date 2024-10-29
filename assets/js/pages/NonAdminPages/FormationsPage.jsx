import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import formationsAPI from "../services/formationsAPI";
import yearsAPI from "../services/yearsAPI";
import moment from "moment";
import Pagination from "../components/Pagination";

import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";

const FormationsPage = (props) => {

    const [formations, setFormations] = useState([]);
    const [years, setYears] = useState([]);
    const [firstUse, setFirstUse] = useState(false);
    const [loading,setLoading] = useState(true);
    const [totalItems, setTotalItems] = useState(0);

    const YEARS_LABELS = {
        1: "Première année",
        2: "Deuxième année",
        3: "Troisième année",
        4: "Quatrième année",
        5: "Cinquième année",
        6: "Sixième année",
        7: "Septième année",
        8: "Huitième année",    
    }

    const EVENT_LABELS = {
        "congres" : "Congrès",
        "lesson": "Cour",
        "journal": "Journal",
        "staff": "Staff"
    }

    //Recherche les année de formation de l'utilisateur :
    
            useEffect(()=>{ fetchYear();},[])

            const fetchYear = async () => {
                try{
                    const data = await yearsAPI.findAll();
                
                    if(data.length == 0){
                        setFirstUse(true);
                    }

                    setYears(data);
                }       
                catch(error){
                    
                }
            }

    // Recherche des évènements de formations de l'utilisateur : 

            useEffect (() => {fetchInfo()},[]);

            const fetchInfo = async () => {
                try {
                    const data = await formationsAPI.findAll();
                    const form = data.data['hydra:member'];
                    
                    setFormations(form);
                    setTotalItems(data.data['hydra:totalItems'])
                    setLoading(false);
                    
                } catch (error) {
                    console.log(error.response)
                }
            }

    // Gestion de la pagination :

            const [currentPage, setCurrentPage] = useState(1);

            const fetchPage = async (page) => {
                try {
                    const data = await formationsAPI.fetchPage(page) ;
                    setFormations(data);
                    setLoading(false);
                } catch (error) {
                    console.log(error.response)
                }
            }

            const handleChangePage = (page) => {
                setCurrentPage(page);
                fetchPage(page)
            };
       
    // Fonction de suppression :
    
            const handleDelete = async id => {
                const originalFormations = [...formations];
                setFormations(formations.filter(formation => formation.id !== id));

            try {
                await formationsAPI.deleteFormation(id)
        
            } catch(error) {
                setFormations(originalFormations);
            }   
            };
            

    return ( 
        <>
        <div className="form-content">
            
            <div className="mb-2 d-flex justify-content-between align-items-center">
                <h1>Formations et publications</h1>
                
                {!firstUse &&
                
                <Link to="/formations/new" className="btn btn-success">Ajouter</Link>
            
            ||

                <Link to="/years/new" className="btn btn-success">Ajouter une année</Link>
            }
            </div>

            {loading && < SpinnerLoader/>}


            {!loading && <table className="responsive-table">
                    
                  
                    <thead>
                        <tr>
                            <th>Année</th>
                            <th>Catégorie</th>
                            <th>Titre</th>
                            <th>Début</th>
                            <th>Fin</th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>

                    

                    {!firstUse && 
                    <tbody>
                        {formations.map(formation => (
                            <tr key={formation.id}>
                                <td data-label="Année">{YEARS_LABELS[formation.year.yearOfFormation]}</td>
                                <td data-label="Catégorie">{EVENT_LABELS[formation.event]}</td>
                                <td data-label="Titre">{formation.name}</td>
                                <td data-label="Début">{moment(formation.dateOfStart, "YYYY-MM-DD, H:mm").format('DD/MM/YYYY à H:mm')}h</td>
                                <td data-label="Fin">{moment(formation.dateOfEnd, "YYYY-MM-DD, H:mm").format('DD/MM/YYYY à H:mm')}h</td>
                                
                                <td className="text-center"><Link to={"/formations/" + formation.id} className="btn btn-sm btn-warning">Modifier</Link></td>
                                <td className="text-center"><button className="btn btn-sm btn-danger" onClick={() => handleDelete(formation.id)}>Supprimer</button></td>
                            </tr>
                        ))}
                    </tbody> ||
                    
                    <tbody>
                        <tr>
                            <td colSpan="7"> Vous n'avez pas encore d'année enregistrées.</td>
                        </tr>
                    </tbody>
                    } 
                            
            </table>} 

            {!loading & (totalItems > 30) &&
            <Pagination currentPage={currentPage} totalItems={totalItems} onPageChanged={handleChangePage}/>|| null
            }
        </div>
        </>
     );
}
 
export default FormationsPage;
