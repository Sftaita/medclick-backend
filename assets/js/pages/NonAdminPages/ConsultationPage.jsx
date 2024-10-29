import React, { useEffect, useState, useContext } from 'react';
import AuthContext from "../contexts/AuthContext";
import Field from "../components/Forms/Field";
import yearsAPI from "../services/yearsAPI";
import Select from "../components/Forms/Select";
import consultationsAPI from '../services/consultationsAPI';
import { Link } from "react-router-dom";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";


import DateFnsUtils from '@date-io/date-fns';
import { KeyboardDatePicker, MuiPickersUtilsProvider } from '@material-ui/pickers';
import 'date-fns';
import moment from "moment";

const ConsultationPage = ({history, match}) => {
    
    const {id = "new"} = match.params;
    

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

    
    const [consultation, setConsultation] = useState({
        date: "",
        dayPart:"",
        speciality:"",
        number:"",
        year:"",
    });

    const [errors, setErrors] = useState({
        date: "",
        dayPart:"",
        speciality:"",
        number:"",
        year:"",
    });

    const [editing,setEditing] = useState(false);
    
    
    //Gestion des champs :

        const handleChange = ({currentTarget}) => {
            const value = currentTarget.value;
            const name = currentTarget.name;
        
            setConsultation({...consultation, [name]:value})
        }

    // Recherche les années de formation de l'utilisateur :

        useEffect(()=>{fetchYears();},[])

        const[years,setYears]= useState([]);
            
        const fetchYears = async () => {
        
            try{
                const data = await yearsAPI.findAll()
                setYears(data);
                if(id === "new"){
                    setConsultation({...consultation, year: `${data[0].id}`},);
                    setSelectedDate({})
                    setLoading(false);
                }

            }catch(error){
                history.replace('/consultations');
            }}


    //------- Si en mode mode modification ------- //
    useEffect(()=>{
        if (id !== "new"){
            fetchConsultation(id);
            setEditing(true);
        }
        
    }, [id])

    
    const fetchConsultation = async id => {
        try{
            const data = await consultationsAPI.find(id)
            const { date,  speciality, number, year, dayPart} = data;
            setConsultation({...consultation, date: moment(date).format("DD-MM-YYYY"), dayPart, speciality, number, year: year.id});
            setLoading(false);
        }catch(error){
            history.replace('/consultations');
        }
    }


    // Gestion du submit.
    const handleSubmit = async (event) => {
        event.preventDefault();
        const apiErrors = {};
        
        try{
            if(editing){
                
                const response = await consultationsAPI.update(id, {...consultation, date: `${moment(consultation.date, "DD-MM-YYYY").format("YYYY-MM-DD")}`, year: `/api/years/${consultation.year}`});
                history.replace("/consultations");
            }else{
                if(consultation.date === ""){
                    apiErrors.date = "Veuillez indiquer la date.";
                    setErrors(apiErrors);
                    return;
                }
                const response = await consultationsAPI.createConsultation({...consultation, year: `/api/years/${consultation.year}`, date: `${moment(consultation.date, "DD-MM-YYYY").format("YYYY-MM-DD")}`}); 
                history.replace("/home");
            }
            
             
        }catch({ response }){
            const {violations} = response.data;
            if(violations){      
                violations.forEach(({propertyPath, message}) => {
                    apiErrors[propertyPath] = message;
                });
                setErrors(apiErrors);         
            };    
        }}

    //----------- Date picker ----------------//
    const [selectedDate, setSelectedDate] = useState();
    const [isOpen, setIsOpen] = useState(false);
    const handleDateChange = (date) => {
        setSelectedDate(date);  
    };

    useEffect(()=>{
        setConsultation({...consultation, date: `${moment(selectedDate).format("DD-MM-YYYY")}`});
    },[selectedDate])
    
    
    // Gestion du spinner
    const [loading,setLoading] = useState(true);

    return ( 
        <>
        <div className="form-content">
            {(!editing && <h1>Ajouter des consultations</h1>) || (<h1>Modifier une série de consultation</h1>)}

            {loading &&  <SpinnerLoader />}
            
            {!loading && <form onSubmit={handleSubmit}>
                <Field name="date" placeholder="Date" label="Date" onChange={handleChange} value={consultation.date} error={errors.date} onClick={() => setIsOpen(true)}></Field>

                <Select name="speciality" placeholder="Spécialité de la consultation" label="Spécialité" onChange={handleChange} value={consultation.speciality} error={errors.speciality}>
                    <option value=""></option>
                    <option value="ortho">Orthopédie</option>
                    <option value="traumato">Traumatologie</option>
                    <option value="dig">Digestive</option>
                    <option value="uro">Urologie</option>
                    <option value="vasc">Vasculaire</option>
                    <option value="plast">Chirurgie plastique</option>
                    
                </Select>
                
                <Select name="dayPart" placeholder="Moment de la journée" label="Horaire" onChange={handleChange} value={consultation.dayPart} error={errors.dayPart}>
                    <option value=""></option>
                    <option value="morning">Matin</option>
                    <option value="afternoon">Après-midi</option>
                    <option value="night">Soir</option>
                </Select>
                
                <Field name="number" placeholder="Nombre de consultation" label="Nombre" onChange={handleChange} value={consultation.number} error={errors.number}></Field>
                
                <Select name="year" placeholder="Année de formation" label="Année" onChange={handleChange} value={consultation.year} error={errors.year}>
                    {years.map(year => (
                        <option key={year.id} value={year.id}>{YEARS_LABELS[year.yearOfFormation]}</option>
                    ))

                    }
                </Select>

                <MuiPickersUtilsProvider utils={DateFnsUtils}>
                
                <KeyboardDatePicker 
                    value={selectedDate}                    
                    onChange={handleDateChange}
                    format="dd/MM/yyyy"
                    margin="normal"
                    TextFieldComponent={() => null}
                    open={isOpen}
                    onOpen={() => setIsOpen(true)}
                    onClose={() => setIsOpen(false)}
                />
                </MuiPickersUtilsProvider>

                <div className="div form-group">
                    <button type="submit" className="btn btn-success">Enregistrer</button>
                    <Link to="/consultations" className="btn btn-link"> Retour</ Link>

                </div>
            </form>}
        </div>
        </>
     );
}
 
export default ConsultationPage;