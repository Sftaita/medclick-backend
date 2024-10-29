import React, { useEffect, useState } from 'react';
import Field from "../components/Forms/Field";
import Select from "../components/Forms/Select";
import CustomSwitch from "../components/Forms/CustomSwitch";
import functions from "../functions/functions";
import yearsAPI from "../services/yearsAPI";
import { Link } from "react-router-dom";
import surgeonsAPI from "../services/surgeonsAPI";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";
import FormControlLabel from '@material-ui/core/FormControlLabel';


const SurgeonPage = ({history, match}) => {

    const {id = "new"} = match.params;
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

    const [surgeon, setSurgeon] = useState({
        firstName: "",
        lastName:"",
        year:"",
        boss: false,
    });

    const [errors, setErrors] = useState({
        year:"",
        firstname: "",
        lastname:"",
        boss: "",   
    })

    
    //Gestion des champs.
    const handleChange = ({currentTarget}) => {
        const value = currentTarget.value;
        const name = currentTarget.name;

        setSurgeon({...surgeon, [name]:value})
    }

    //------- Si en mode mode modification ------- //
    const [editing,setEditing] = useState(false);
    
    
    useEffect(()=>{
        if (id !== "new"){
            fetchSurgeon(id);
            setEditing(true);
        }
    }, [id])

    const fetchSurgeon = async id => {
        try{
            const data = await surgeonsAPI.find(id)
            .then(response => response.data);

            const { firstName, lastName, year, boss} = data;
            setSurgeon({ firstName, lastName, year : year.id, boss});
            setBoss({...boss, checked: boss })
            
        }catch(error){
            history.replace('/surgeons');
        }
    }


    // Recherche des années de formation de l'utilisateur :
    const [years, setYears] = useState([]);                             

    const fetchYear = async () => {
        try{
            const data = await yearsAPI.findAll();
            setYears(data);
            setLoading(false);
                            
            if(id === "new"){
                setSurgeon({...surgeon, year: `${data[0].id}`})   
            }   
        }       
        catch(error){
            console.log(error.response);
        }
    }

    useEffect (() => {fetchYear()},[]);

    // Gestion du submit :
    const handleSubmit = async (event) => {
        event.preventDefault();
        
        try{
            if(editing){
                await surgeonsAPI.update(id,{ ...surgeon, year: `/api/years/${surgeon.year}`});
                history.replace("/surgeons");
            }else{
                await surgeonsAPI.createSurgeon({...surgeon, year: `/api/years/${surgeon.year}`});
                history.replace("/surgeons");
            }
            
             
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

    // Gestion du switch :

        const [boss, setBoss] = useState({
            checked: false,
        });

        const switchChange = (event) => {
            setBoss({ ...boss, [event.target.name]: event.target.checked });
          };

        useEffect(()=>{ setSurgeon({...surgeon, boss: boss.checked}) }, [boss.checked])

    return ( 
        <>
        <div className="form-content">

        {loading && <SpinnerLoader/>}
        {!loading && <div>
            {(!editing && <h1>Ajouter un superviseur</h1>) || (<h1>Modifier un superviseur</h1>)}


            <form onSubmit={handleSubmit}>

                <Field name="firstName" label="" placeholder="Prénom" value={functions.capitalize(surgeon.firstName)} onChange={handleChange} error={""}></Field>
                
                <Field name="lastName" label="" placeholder="Nom" value={functions.capitalize(surgeon.lastName)} onChange={handleChange} error={""}></Field>

                <Select name="year" label="Année de formation" value={surgeon.year} error={errors.year} onChange={handleChange}>
                        {years.map(year => (
                            <option key={year.id} value={year.id} >
                            {YEARS_LABELS[year.yearOfFormation]}
                            </option>
                        ))}
                </Select>

                <CustomSwitch checked={boss.checked} onChange={switchChange} name="checked" color="primary" label="Maître de stage"/>

                <div className="form-group">
                    {!editing && <button type="submit" className="btn btn-success">Ajouter</button>}
                    {editing && <button type="submit" className="btn btn-warning">Modifier</button>}
                    <Link to="/surgeons" className="btn btn-link">Retour</Link>
                </div>
            </form>
        </div>}  
        </div>  
        </>
     );
}
 
export default SurgeonPage;