import React, {useEffect, useState, useContext} from 'react';
import AuthContext from "../contexts/AuthContext";
import axios from "axios";
import moment from "moment";
import consultationsAPI from '../services/consultationsAPI';
import { Link } from 'react-router-dom';
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";
import Pagination from "../components/Pagination";




const ConsultationsPage = (props) => {

        
    const [consultations, setConsultations] = useState ([]);
    const [loading,setLoading] = useState(true);
    const [firstUse, setFirstUse] = useState(false);
    const [totalItems, setTotalItems] = useState(0);

    const SPECIALITY_LABELS = {
        ortho: "Orthopédie",
        vasc: "Vaculaire",
        uro: "Urologie",
        plast: "Chir plastique",
        dig: "Digestive",
        traumato: "Traumatologie",  

    }

    const HORAIRE_LABELS = {
        morning: "Matin",
        afternoon: "Après-midi",
        night: "Soir"  
    }

    const YEARS_LABELS = {
        1: "Première",
        2: "Deuxième",
        3: "Troisième",
        4: "Quatrième",
        5: "Cinquième",
        6: "Sixième",
        7: "Septième",
        8: "Huitième",
        
    }

    // Recherche des consultations.
    const fetchConsultations = async () => {
        try {
            const data = await consultationsAPI.findAll();
            const cons = data.data['hydra:member'];
            setConsultations(cons);
            setTotalItems(data.data['hydra:totalItems'])
            setLoading(false)

            if(data.length == 0){
                setFirstUse(true);
            };
        } catch (error) {
            console.log(error.response)
        }
    }

    useEffect(()=>{ 
        fetchConsultations();
        
    },[])

      // Gestion de la pagination :

        const [currentPage, setCurrentPage] = useState(1);

        const fetchPage = async (page) => {
            try {
                const data = await consultationsAPI.fetchPage(page) ;
                setConsultations(data);
                setLoading(false);
            } catch (error) {
                console.log(error.response)
            }
        }

        const handleChangePage = (page) => {
            setCurrentPage(page);
            fetchPage(page)
        };


    //Fonction de suppression
    const handleDelete = async id => {
        const originalConsultations = [...consultations];
        setConsultations(consultations.filter(consultation => consultation.id !== id));

        try{
            await consultationsAPI.deleteConsultation(id)
        
        } catch(error) {
            setConsultations(originalConsultations);
        }   
    };

    return (
        <>
        <div className="form-content">
            <div className="mb-2 d-flex justify-content-between align-items-center">
                <h1>Mes consultations</h1>

                {!firstUse &&
                
                    <Link to="/consultations/new" className="btn btn-success">Ajouter</Link>
                
                ||

                    <Link to="/years/new" className="btn btn-success">Ajouter une année</Link>
                }
                
            </div>

            {loading && < SpinnerLoader/>}
            {!loading && <table className="responsive-table">
                <thead>
                    <tr>
                        <th className="text-center">Année</th>
                        <th>Date</th>
                        <th>Horaire</th>
                        <th>Spécialité</th>
                        <th className="text-center">Nombre de consultation</th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>

                {!firstUse && 
                    <tbody>
                    {consultations.map(consultation => (
                        <tr key={consultation.id}>
                            <td className="custom-text-center" data-label="Année">{YEARS_LABELS[consultation.year.yearOfFormation]}</td>
                            <td data-label="Date">{moment(consultation.date).format('DD/MM/YYYY')}</td>
                            <td data-label="Horaire">{HORAIRE_LABELS[consultation.dayPart]}</td>
                            <td data-label="Spécialité">{SPECIALITY_LABELS[consultation.speciality]}</td>
                            <td className="custom-text-center" data-label="Nombre">{consultation.number}</td>
                            <td className="text-center"><button className="btn btn-sm btn-danger" onClick={() => handleDelete(consultation.id)}>Supprimer</button></td>
                            <td className="text-center"><Link to={"/consultations/" + consultation.id} className="btn btn-sm btn-warning">Modifier</Link></td>
                        </tr>

                    ))
                    }
                    </tbody>

                ||

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
 
export default ConsultationsPage;