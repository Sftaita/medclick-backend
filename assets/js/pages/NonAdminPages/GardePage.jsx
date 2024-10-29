import React, { useEffect, useState } from 'react';
import Field from "../components/Forms/Field";
import Select from "../components/Forms/Select";
import { Link } from "react-router-dom";
import yearsAPI from "../services/yearsAPI";
import formationsAPI from "../services/formationsAPI";
import gardesAPI from "../services/gardesAPI";

import DateFnsUtils from '@date-io/date-fns';
import { KeyboardDateTimePicker, MuiPickersUtilsProvider } from '@material-ui/pickers';
import 'date-fns';
import moment from "moment";

import Slider from '@material-ui/core/Slider';

import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";

const GardePage = ({history, match}) => {

    const {id = "new"} = match.params;

    const [garde, setGarde] = useState({
        year:"",
        dateOfStart:"",
        dateOfEnd:"",
        number:""       
    });

  

    const [errors, setErrors] = useState({
        year:"",
        dateOfStart:"",
        dateOfEnd:"",
        number:""  
    })

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

  
    // Gestion du spinner
    
            const [loading,setLoading] = useState(true);


    //Gestion des champs.
            
            const handleChange = ({currentTarget}) => {
                const value = currentTarget.value;
                const name = currentTarget.name;

                setGarde({...garde, [name]:value})
            }

    // Gestion du date picker
            
            const [isOpen1, setIsOpen1] = useState(false);
            const [selectedDate1, setSelectedDate1] = useState();
            
            const handleDateChange1 = (date) => {
                setSelectedDate1(date);  
            };

            useEffect(()=>{
                setGarde({...garde, dateOfStart: `${moment(selectedDate1).format("DD-MM-YYYY, H:mm")}`});
            },[selectedDate1])

    
            const [isOpen2, setIsOpen2] = useState(false);
            const [selectedDate2, setSelectedDate2] = useState();

            const handleDateChange2 = (date) => {
                setSelectedDate2(date);  
            };

            useEffect(()=>{
                setGarde({...garde, dateOfEnd: `${moment(selectedDate2).format("DD-MM-YYYY, H:mm")}`});
            },[selectedDate2])


    // Recherche les années de formation de l'utilisateur.
     
            useEffect(()=>{fetchYears();},[])

            const[years,setYears]= useState([]);
                
            const fetchYears = async () => {
                try{
                    const data = await yearsAPI.findAll()
                    setYears(data);
                    setGarde({...garde, year: `${data[0].id}`});

                    if(id === "new"){
                        setLoading(false);
                    }
                      
                }catch(error){
                    history.replace('/gardes');
                }}


    // Si en mode mode modification :
    
            const [editing,setEditing] = useState(false);
    
            useEffect(()=>{
                if (id !== "new"){
                    fetchGarde(id);
                    setEditing(true);
                }
            }, [id])

            
            const fetchGarde = async id => {
                
                try{
                    const data = await gardesAPI.find(id)
                    const { year, dateOfStart, dateOfEnd, number} = data;

                    setGarde({ dateOfStart: moment(dateOfStart, "YYYY-MM-DD, H:mm").format("DD-MM-YYYY à H:mm"), dateOfEnd: moment(dateOfEnd, "YYYY-MM-DD, H:mm").format("DD-MM-YYYY, H:mm"), number , year : year.id});
                    setSelectedDate1(moment(dateOfStart,"YYYY-MM-DD, H:mm"));
                    setSelectedDate2(moment(dateOfEnd, "YYYY-MM-DD, H:mm"));
                    setBase(parseInt(number));
                    
                    setLoading(false);
                    

                }catch(error){
                    history.replace('/gardes');
                }
            }

        // Gestion du slider :
        
            const mark = [
                {
                    value: 0,
                    label: "0"
                },
                {
                    value: 10,
                    label: "10"
                },
                {
                    value: 20,
                    label: "20"
                },
                {
                    value: 30,
                    label: "30"
                },
                {
                    value: 40,
                    label: "40"
                }
            ]

            const [base, setBase] = useState(0);
            const [selectedNumber, setSelectedNumber] = useState();
            const getValue = (e, value) => {
                setSelectedNumber(value);
            }
            useEffect(()=>{
                setGarde({...garde, number: '' + selectedNumber});
            },[selectedNumber])

            
            
    // Gestion du submit .
    
    
            const handleSubmit = async (event) => {
                event.preventDefault();
                const apiErrors = {};

                if(garde.dateOfStart === ""){
                    apiErrors.dateOfStart = "Quand la garde a t-elle commencée ?";
                    setErrors(apiErrors);
                    return;
                }

                if(garde.dateOfEnd === ""){
                    apiErrors.dateOfEnd = "Quand la garde a t-elle fini ?"
                    setErrors(apiErrors);
                    return;
                }
        
                try{
                    if(editing){

                        await gardesAPI.update(id, {...garde, year: `/api/years/${garde.year}`});
                        history.replace("/gardes");
                        console.log(garde);
                    }else{
                       
                        await gardesAPI.create({...garde, year: `/api/years/${garde.year}`});

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
                    
                }
                }


    return ( 
        <>
            <div className="form-content">
                {!editing && <h1>Ajouter une garde</h1>  || <h1>Modifier une garde</h1>}

                {loading && < SpinnerLoader/>}

                {!loading && 
                <form onSubmit={handleSubmit}>

                    <Select name="year" placeholder="Année de formation" label="Année" onChange={handleChange} value={garde.year} error={errors.year}>
                        {years.map(year => (
                            <option key={year.id} value={year.id}>{YEARS_LABELS[year.yearOfFormation]}</option>
                        ))}
                    </Select>
                    
                    <Field name="dateOfStart" placeholder="Date et heure de début" label="Date de début" onChange={handleChange} value={garde.dateOfStart} error={errors.dateOfStart} onClick={() => setIsOpen1(true)}></Field>

                    <Field name="dateOfEnd" placeholder="Date et heure de fin" label="Date de fin" onChange={handleChange} value={garde.dateOfEnd} error={errors.dateOfEnd} onClick={() => setIsOpen2(true)}></Field>


                    <div className="form-group">
                        <label>Nombre de patient</label>
                        <div style ={{marginTop: 50}}>
                        <Slider 
                            color ="primary"
                            defaultValue={base}
                            max={40}
                            step={1}
                            marks={mark}
                            valueLabelDisplay="on"
                            onChange={getValue}
                        />         
                            
                        </div>
                    </div>
                              
                            <MuiPickersUtilsProvider utils={DateFnsUtils}>
                        
                                    <KeyboardDateTimePicker 
                                        value={selectedDate1}                    
                                        onChange={handleDateChange1}
                                        format="dd/MM/yyyy H:mm"
                                        margin="normal"
                                        minutesStep={5}
                                        TextFieldComponent={() => null}
                                        open={isOpen1}
                                        ampm={false}
                                        onOpen={() => setIsOpen1(true)}
                                        onClose={() => setIsOpen1(false)}
                                        
                                    />

                                    <KeyboardDateTimePicker 
                                        value={selectedDate2}                    
                                        onChange={handleDateChange2}
                                        format="dd/MM/yyyy H:mm"
                                        margin="normal"
                                        ampm={false}
                                        minutesStep={10}
                                        TextFieldComponent={() => null}
                                        minDate= {selectedDate1}
                                        open={isOpen2}
                                        onOpen={() => setIsOpen2(true)}
                                        onClose={() => setIsOpen2(false)}
                                    />
                            </MuiPickersUtilsProvider>

                            
                        

                    
                            <div className="form-group">
                                {!editing && <button type="submit" className="btn btn-success">Ajouter</button>}
                                {editing && <button type="submit" className="btn btn-warning">Modifier</button>}
                                <Link to="/gardes" className="btn btn-link">Retour</Link>
                            </div>
                    
                </form>}
            </div>
        </>
     );
}
 
export default GardePage;