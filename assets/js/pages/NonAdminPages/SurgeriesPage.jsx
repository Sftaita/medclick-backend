import React, { useEffect, useState, useContext } from 'react';
import AuthContext from "../contexts/AuthContext";
import surgeriesAPI from '../services/surgeriesAPI';
import moment from "moment";
import { Link} from "react-router-dom";
import yearsAPI from "../services/yearsAPI";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";
import Pagination from "../components/Pagination";


const SurgeriesPage = (props) => {    

    const [surgeries, setSurgeries] = useState([]);
    const [loading,setLoading] = useState(true);
    

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

    const POSITION_LABELS = {
        1: "Première main (solo)",
        2: "Deuxième main",
        3: "Première main (accompagné)",
    }

    // Recherche des interventions :
            
            const [firstUse, setFirstUse] = useState(false);
            const [totalItems, setTotalItems] = useState(0);
    
            useEffect(()=>{ 
                fetchSurgeries(), fetchYear();
            },[])
            
            
            const fetchSurgeries = async () => {
                try {
                    const data = await surgeriesAPI.findAll() ;
                    const surg = data.data['hydra:member'];
                     
                    setSurgeries(surg);
                    setTotalItems(data.data['hydra:totalItems'])
                    setLoading(false);
           
                } catch (error) {
                    console.log(error.response)
                }
            }

    //Recherche les années de formations de l'utilisateur :
            
            const fetchYear = async () => {
                try{
                    const data = await yearsAPI.findAll();
                
                    if(data.length == 0){
                        setFirstUse(true);
                    }
                }       
                catch(error){  
                }
            }
    

    //Fonction de suppression :

            const handleDelete = async id => {
                const originalSurgeries = [...surgeries];
                setSurgeries(surgeries.filter(surgery => surgery.id !== id));
                    try{
                        await surgeriesAPI.deleteSurgery(id)
                    } catch(error) {
                        setSurgeries(originalSurgeries);
                    }   
            };
    
    //Formataur de date
            
            const formatDate = (str) => moment(str).format('DD/MM/YYYY');

    
    // Gestion de la pagination :

            const [currentPage, setCurrentPage] = useState(1);

            const fetchPage = async (page) => {
                try {
                    const data = await surgeriesAPI.fetchPage(page) ;
                    setSurgeries(data);
                    setLoading(false);
                } catch (error) {
                    console.log(error.response)
                }
            }

            const handleChangePage = (page) => {
                setCurrentPage(page);
                fetchPage(page)
            };
    
    return ( 
        <>
        <div className="form-content">
            <div className="mb-2 d-flex justify-content-between align-items-center">
                <h1> Mes Interventions </h1>
                {(firstUse == false) && 
                    (<Link to="/surgeries/new" className="btn btn-success">Ajouter</Link>) ||
                    (<Link to="/years/new" className="btn btn-success">Ajouter une année</Link>)
                }


            </div>

            {!loading && (
            <table className="responsive-table">
                
                <thead>
                    <tr>
                        <th>Année de formation</th>
                        <th>Date</th>
                        <th>Intervention</th>
                        <th>Position</th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>


               {!firstUse && 
                    <tbody>
                        {surgeries.map(surgery => (
                            <tr key={surgery.id}>
                                <td data-label="Année">{YEARS_LABELS[surgery.year.yearOfFormation]}</td>
                                <td data-label="Date">{formatDate(surgery.date)}</td>
                                <td data-label="Intervention" className="justify">{surgery.name}</td>
                                <td data-label="Position" >{POSITION_LABELS[surgery.position]}</td>
                                <td className="center">
                                    <Link to={"/surgeries/" + surgery.id} className="btn btn-sm btn-warning">Modifier
                                    </Link>
                                </td>
                                <td className="center"><button 
                                    onClick={() => handleDelete(surgery.id)}
                                    className="btn btn-sm btn-danger">Supprimer</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>}

                {firstUse && 
                    <tbody>
                        <tr>
                            <td colSpan="6"> Vous n'avez pas encore d'année enregistrées.</td>
                        </tr>
                    </tbody>
                }
            </table> 
            )}

            {!loading & (totalItems > 30) &&
                <Pagination currentPage={currentPage} totalItems={totalItems} onPageChanged={handleChangePage}/>|| null
            }

            {loading && < SpinnerLoader/>}
                           
        </div>   
        </>
     );
}
 
export default SurgeriesPage;