import DateFnsUtils from '@date-io/date-fns';
import { KeyboardDatePicker, MuiPickersUtilsProvider } from '@material-ui/pickers';
import 'date-fns';
import moment from "moment";
import React, { useEffect, useState } from 'react';
import { Link } from "react-router-dom";
import Field from "../components/Forms/Field";
import Select from "../components/Forms/Select";
import yearsAPI from "../services/yearsAPI";
import functions from "../functions/functions";


const YearPage = ({history, match}) => {
    
    const {id = "new"} = match.params;

    const [year, setYear] = useState({
        yearOfFormation: "",
        dateOfStart:"",
        hospital:"",
        master:""

    });

    const [errors, setErrors] = useState({
        yearOfFormation:"",
        dateOfStart:"",
        hospital:"",
        master:""

    });

    //----------- Date picker ----------------//
    const [selectedDate, setSelectedDate] = useState();
    const [isOpen, setIsOpen] = useState(false);
    const handleDateChange = (date) => {
        setSelectedDate(date);  
    };

    useEffect(()=>{
        setYear({...year, dateOfStart: `${moment(selectedDate).format("DD-MM-YYYY")}`});
    },[selectedDate])
   

    //------- Si en mode mode modification ------- //
    const [editing,setEditing] = useState(false);
    
    useEffect(()=>{
        if (id !== "new"){
            fetchYear(id);
            setEditing(true);
        }
    }, [id])

    const fetchYear = async id => {
        try{
            const data = await yearsAPI.find(id)
            .then(response => response.data);

            const { yearOfFormation, dateOfStart, hospital, master} = data;
            setYear({yearOfFormation, dateOfStart: moment(dateOfStart).format("DD-MM-YYYY"), hospital, master});

        }catch(error){
            history.replace('/years');
        }
    }
    //-------------------------------------------//


    //Gestion des champs
    const handleChange = ({currentTarget}) => {
    const value = currentTarget.value;
    const name = currentTarget.name;

    setYear({...year, [name]:value})
    }

    // Gestion du submit.
    const handleSubmit = async (event) => {
        event.preventDefault();
        
        try{
            if(editing){
                setYear({...year, dateOfStart: `${moment(year.dateOfStart, "DD-MM-YYYY").format("YYYY-MM-DD")}`});
                const response = await yearsAPI.update(id, year);
                
                history.replace("/years");
            }else{
                const response = await yearsAPI.create({...year, dateOfStart: `${moment(year.dateOfStart, "DD-MM-YYYY").format("YYYY-MM-DD")}`});
                history.replace("/years");
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
    
    return ( 
    <>
    <div className="form-content">
        {(!editing && <h1>Ajouter une année</h1>) || (<h1>Modifier une année</h1>)}

        <form onSubmit={handleSubmit}>
            
            <Select name="yearOfFormation" label="Année de formation" value={year.yearOfFormation} error={errors.yearOfFormation} onChange={handleChange}>
                <option value=""></option>
                <option value="1">Première année</option>
                <option value="2">Deuxième année</option>
                <option value="3">Troisième année</option>
                <option value="4">Quatrième année</option>
                <option value="5">Cinquième année</option>
                <option value="6">Sixième année</option>
                <option value="7">Septième année</option>
                <option value="8">Huitième année</option>
            </Select>

            <Field name="dateOfStart" placeholder="Début de stage" label="Début" onChange={handleChange} onClick={() => setIsOpen(true)} value={year.dateOfStart} error={errors.dateOfStart}></Field>

            <Field name="hospital" placeholder="Hôpital de stage" label="Hôpital" onChange={handleChange} value={functions.capitalize(year.hospital)} error={errors.hospital}></Field>

            <Field name="master" placeholder="Maître de stage" label="Maître de stage" onChange={handleChange} value={functions.capitalize(year.master)} error={errors.master}></Field>

            <div className="form-group">
                <button type="submit" className="btn btn-success">Ajouter</button>
                <Link to="/years" className="btn btn-link">Retour</Link>
            </div>

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
        </form>
    </div>
    </> 
    );
}
 
export default YearPage;