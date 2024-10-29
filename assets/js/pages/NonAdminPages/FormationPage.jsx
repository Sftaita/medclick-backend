import React, { useEffect, useState } from 'react';
import Field from "../components/Forms/Field";
import Select from "../components/Forms/Select";
import CustomSwitch from "../components/Forms/CustomSwitch";
import { Link } from "react-router-dom";
import yearsAPI from "../services/yearsAPI";
import formationsAPI from "../services/formationsAPI";

import DateFnsUtils from '@date-io/date-fns';
import { KeyboardDateTimePicker, MuiPickersUtilsProvider } from '@material-ui/pickers';
import 'date-fns';
import moment from "moment";

import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";

const FormationPage = ({history, match}) => {

    const {id = "new"} = match.params;

    const [formation, setFormation] = useState({
        year:"",
        event: "",
        dateOfStart:"",
        dateOfEnd:"",
        name:"",
        description:"",
        location:"",
        role:"",
        
    });


    const [errors, setErrors] = useState({
        year:"",
        event: "",
        dateOfStart:"",
        dateOfEnd:"",
        name:"",
        description:"",
        location:"",
        role:"",  
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

                setFormation({...formation, [name]:value})
            }

    // Gestion du date picker
            
            const [isOpen1, setIsOpen1] = useState(false);
            const [selectedDate1, setSelectedDate1] = useState();
            
            const handleDateChange1 = (date) => {
                setSelectedDate1(date);  
            };

            useEffect(()=>{
                setFormation({...formation, dateOfStart: `${moment(selectedDate1).format("DD-MM-YYYY, H:mm")}`});
            },[selectedDate1])

    
            const [isOpen2, setIsOpen2] = useState(false);
            const [selectedDate2, setSelectedDate2] = useState();

            const handleDateChange2 = (date) => {
                setSelectedDate2(date);  
            };

            useEffect(()=>{
                setFormation({...formation, dateOfEnd: `${moment(selectedDate2).format("DD-MM-YYYY, H:mm")}`});
            },[selectedDate2])


    // Recherche les années de formation de l'utilisateur.
     
            useEffect(()=>{fetchYears();},[])

            const[years,setYears]= useState([]);
                
            const fetchYears = async () => {
                try{
                    const data = await yearsAPI.findAll()
                    setYears(data);
                    setFormation({...formation, year: `${data[0].id}`});

                    if(id === "new"){
                        setLoading(false);
                    }
                      
                }catch(error){
                    history.replace('/formations');
                }}


    // Si en mode mode modification .
    
            const [editing,setEditing] = useState(false);
    
            useEffect(()=>{
                if (id !== "new"){
                    fetchFormation(id);
                    setEditing(true);
                }
            }, [id])

            
            const fetchFormation = async id => {
                
                try{
                    const data = await formationsAPI.find(id)
                    const { event, dateOfStart, dateOfEnd, name, description, location, role, year} = data;

                    setFormation({ event, dateOfStart: moment(dateOfStart, "YYYY-MM-DD, H:mm").format("DD-MM-YYYY, H:mm"), dateOfEnd: moment(dateOfEnd, "YYYY-MM-DD, H:mm").format("DD-MM-YYYY, H:mm"), name, description, location, role, year : year.id});
                    setSelectedDate1(moment(dateOfStart,"YYYY-MM-DD, H:mm"));
                    setSelectedDate2(moment(dateOfEnd, "YYYY-MM-DD, H:mm"));
                    
                    // Gestion du checkbox

                    if(formation.location === "local"){
                        setCheck({...check, check:true});
                    }else{
                        setCheck({...check, check:false});
                        
                    }

                    setLoading(false);
                    

                }catch(error){
                    history.replace('/formations');
                }
            }

    // Gestion du submit .
    
    
            const handleSubmit = async (event) => {
                event.preventDefault();
        
                try{
                    if(editing){

                        if(check.check == true){
                            await formationsAPI.update(id, {...formation, year: `/api/years/${formation.year}`, location: `local`});   
                        }

                        if(check.check == false){
                            await formationsAPI.update(id, {...formation, year: `/api/years/${formation.year}`});
                        }
                        history.replace("/formations");
                    }else{
                       
                        if(check.check == true){
                            await formationsAPI.create({...formation, year: `/api/years/${formation.year}`, location: `local`});   
                        }
                        
                        if(check.check == false){
                            await formationsAPI.create({...formation, year: `/api/years/${formation.year}`});
                        }
                        history.replace("/home");
                     
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

    

    // Algorythme :
    
            const [timeSet, setTimeSet] = useState(false);
            const [active, setActive] = useState(true);
    
    
            useEffect(()=>{
                if(formation.type === "congres" || formation.type === "lesson" || formation.type === "staff" || formation.type === "journal"){setTimeSet(true);   

                setActive(true);
                setFormation({...formation, role: `${""}`});
                }   
            } , [formation.type] )

            useEffect(()=>{
                if(formation.role === "participant"){setActive(false)};    
            } , [formation.role] )


    // Checkbox :

            const [check,setCheck] = useState({
                check: true
            });

            const switchChange = (event) => {
            setCheck({ ...check, [event.target.name]: event.target.checked });
            };

                   

    return ( 
        <>
            <div className="form-content">
                <h1>Ajouter une formation</h1>

                {loading && < SpinnerLoader/>}

                {!loading && 
                <form onSubmit={handleSubmit}>

                    <Select name="year" placeholder="Année de formation" label="Année" onChange={handleChange} value={formation.year} error={errors.year}>
                        {years.map(year => (
                            <option key={year.id} value={year.id}>{YEARS_LABELS[year.yearOfFormation]}</option>
                        ))}
                    </Select>

                    <Select name="event" label="Evènement" placeholder="De quel type d'évènement s'agit-il?" onChange={handleChange} value={formation.event} error={""}>
                        <option value="">Choisir un évènement</option>
                        <option value="congres">Congrès</option>
                        <option value="lesson">Cours</option>
                        <option value="journal">Journal</option>
                        <option value="staff">Staff</option>
                    </Select>
                    
                    <Field name="dateOfStart" placeholder="Date et heure de début" label="Date de début" onChange={handleChange} value={formation.dateOfStart} error={errors.dateOfStart} onClick={() => setIsOpen1(true)}></Field>

                    <Field name="dateOfEnd" placeholder="Date et heure de fin" label="Date de fin" onChange={handleChange} value={formation.dateOfEnd} error={errors.dateOfEnd} onClick={() => setIsOpen2(true)}></Field>
                          

                    <Field name="name" placeholder="Titre" label="Titre" onChange={handleChange} value={formation.name} error={errors.name}></Field>

                    <CustomSwitch checked={check.check} onChange={switchChange} name="check" color="primary" label="A l'hôpital de stage"/>

                        {!check.check && <Field name="location" placeholder="Lieu de l'évènement" label="Lieu" onChange={handleChange} value={formation.location} error={errors.location}></Field>}
                            

                            
                    <Select name="role" label="Rôle" placeholder="Role" onChange={handleChange} value={formation.role} error={errors.role}>
                        <option value="">Choisir</option>  
                        <option value="participant">Participant</option>
                        <option value="speaker">Orateur</option>
                        <option value="organiser">Organisateur</option>
                    </Select>

                    <Field name="description" placeholder="Desciption" label="Description" onChange={handleChange} value={formation.description} error={errors.description}></Field>                                
                              
                        
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
                                <Link to="/formations" className="btn btn-link">Retour</Link>
                            </div>
                    
                </form>}
            </div>
        </>
     );
}
 
export default FormationPage;