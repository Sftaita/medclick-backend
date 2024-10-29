import React, { useEffect, useState } from 'react';
import Select from "../components/Forms/Select";
import yearsAPI from "../services/yearsAPI";
import functions from "../functions/functions";
import surgeonsAPI from "../services/surgeonsAPI";
import { Link } from 'react-router-dom';
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";
import AccountCircleIcon from '@material-ui/icons/AccountCircle';
import StarsIcon from '@material-ui/icons/Stars';

const SurgeonsPage = (history) => {

    // ToDo: Lorsuqe l'utilisateur modifie une intervention, lors d'un changement d'anné, il doit rechoisir la deuxième main.
    
    const [editing,setEditing] = useState(false);
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
    
    const [thisYear, setThisYear] = useState ({
        year:""
    });

    const [errors, setErrors] = useState({
        year:"",
        firstname: "",
        lastname:""   
    })

    const [years, setYears] = useState([]);
    const [surgeons, setSurgeons] = useState ([]);

    useEffect (() => {fetchYear()},[]);
    useEffect (() => {fetchSurgeonsList()},[thisYear]);

    

    // Recherche des interventions
    const fetchSurgeonsList = async () => {
        if(thisYear.year !== ""){
            
            try {
                const data = await surgeonsAPI.findSurgeons(thisYear.year)
                .then(response =>response.data);

                setSurgeons(data);
                setLoading(false);
            
            } catch (error) {
                console.log(error.response)
            }}
        }


    //Recherche les année de formation de l'utilisateur. 
    const fetchYear = async () => {
        try{
            const data = await yearsAPI.findAll();
            setYears(data);
            setThisYear({...thisYear, year: `${data[0].id}`})
            setCreate({...create, year: `${data[0].id}`})         
            
        }       
        catch(error){
            console.log(error.response);
        }
    }

    //Gestion des champs
    const handleChange = ({currentTarget}) => {
        const value = currentTarget.value;
        const name = currentTarget.name;

        setThisYear({...thisYear, [name]:value})
        setCreate({...create, year:value})
    }

    //Fonction de suppression
    const handleDelete = async id => {
        const originalSurgeons = [...surgeons];
        
        setSurgeons(surgeons.filter(surgereon => surgereon.id !== id));

        try{
            await surgeonsAPI.deleteSurgeon(id)
        } catch(error) {
            setSurgeries(originalSurgeries);
        }   
    };
    
    
    //---------------  Partie creation  ---------------//

    const [create, setCreate] = useState({
        year:"",
        firstName: "",
        lastName:""   
    })



    //Gestion des champs du mode édition.
    const handleChange2 = ({currentTarget}) => {
        const value = currentTarget.value;
        const name = currentTarget.name;

        setCreate({...create, [name]:functions.capitalize(value)})
    }

    // Gestion de la soumission
    const handleSubmit = async (event) => {
        event.preventDefault();
                   
        try{ 
            await surgeonsAPI.createSurgeon({...create, year: `/api/years/${create.year}`});
            setEditing(false);
            fetchSurgeonsList();
            
            
            

        }catch(error){
            console.log(error.response)
            
        }
    }      

        
    return ( 
        <>
        <div className="form-content">
        {loading && <SpinnerLoader/>}
        {!loading &&<div>
            <div className="mb-2 d-flex justify-content-between align-items-center">
                <h1>Mes superviseurs</h1> 

                <Link to="/surgeons/new" className="btn btn-success">Ajouter</Link>
            </div>


            <Select name="year" label="Année de formation" value={thisYear.year} error={errors.year} onChange={handleChange} onClick={fetchSurgeonsList} >
                {years.map(year => (
                    <option key={year.id} value={year.id} >
                       {YEARS_LABELS[year.yearOfFormation]}
                    </option>
                ))}
            </Select>

            {!editing &&                
                <table className="responsive-table">
                    <thead>
                        <tr>
                            <th>Prénom</th>
                            <th>Nom</th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        {surgeons.map(sur => (
                            <tr key={sur.id}>
                                <td data-label="Prénom">{sur.boss && <StarsIcon color="primary"/> || <AccountCircleIcon/>}  {sur.firstName}</td>
                                <td data-label="Nom">{sur.lastName}</td>
                                <td className="center"><Link to={"/surgeons/" + sur.id} className="btn btn-sm btn-warning">Modifier</Link></td>
                                <td className="center"><button onClick={() => handleDelete(sur.id)}className="btn btn-sm btn-danger">Supprimer</button></td>
                            </tr>
                        ))}
                    </tbody> 
                            
                </table> 
            }
        </div>}
        </div>   
        </>
     );
}
 
export default SurgeonsPage;