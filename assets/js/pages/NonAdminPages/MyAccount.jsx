import React, { useState, useEffect}from 'react';
import Field from "../components/Forms/Field";
import userAPI from "../services/userAPI";
import functions from "../functions/functions";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";
import '../Styles/account.css';


const MyAccount = (props) => {

    const [user, setUser] = useState({
        firstname:"",
        lastname: "",
        
    })

    const [errors,setErrors] = useState({
        firstname:"",
        lastname: "",
        
    });

    const [loading,setLoading] = useState(true);
    const [stat, setStat] = useState([])
    const [id, setId] = useState()

    
    // Recherche des données :
    
    useEffect(()=>{fetch();},[])
    
    const fetch = async () => {
        try{
            const data = await userAPI.fetch();
            const { firstname, lastname} = data[0];
            setUser({firstname, lastname});
            setId(data[0].id);

            
            //setLoading(false);
        }catch(error){
            history.replace('/');
        }
    }

    useEffect(()=>{statistics();},[id])

    const statistics = async () => {
        try{
            const request =  await userAPI.getStat(id);
            setStat(request);
            setLoading(false);
        }catch(error){
            history.replace('/');
        }
    }

    
            console.log(stat);

    
    //Gestion des champs :

            const handleChange = ({currentTarget}) => {
                    const value = currentTarget.value;
                    const name = currentTarget.name;
    
                    setUser({...user, [name]:value})
            }

    // Gestion du submit :

            const handleSubmit = async (event) => {
                event.preventDefault();
        
                    try{
                        await userAPI.update(id, user);
                        history.replace("/account");           
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
                <h1> Mon compte</h1>

                {loading && <SpinnerLoader/>
                ||
                    <div className="card mt-4 mb-4">
                        <div className="card-header">
                            <h5>{"Dr " + functions.capitalize(user.firstname) + " " + functions.capitalize(user.lastname)}</h5>
                        </div>
                        
                        <div className="responsive-container-50">
                        <ul className="list-group mt-2 mb-2 ml-2">
                            <li className="list-group-item d-flex justify-content-between align-items-center">
                                Année(s)
                                <span className="badge badge-info badge-pill">{stat.length}</span>
                            </li>

                            <li className="list-group-item d-flex justify-content-between align-items-center">
                                Consultation(s)
                                <span className="badge badge-info badge-pill">Travaux</span>
                            </li>
                            <li className="list-group-item d-flex justify-content-between align-items-center">
                                Chirurgie(s)
                                <span className="badge badge-info badge-pill">Travaux</span>
                            </li>

                            <li className="list-group-item d-flex justify-content-between align-items-center">
                                Garde(s)
                                <span className="badge badge-info badge-pill">Travaux</span>
                            </li>
                        </ul>
                    </div>
                </div>
                }

                

                
                {/*<form onSubmit={handleSubmit}>

                    <Field name="firstname" label="Prénom" placeholder="Quel est votre prénom" error= {errors.firstname} value={functions.capitalize(user.firstname)} onChange={handleChange}></Field>

                    <Field name="lastname" label="Nom" placeholder="Quel est votre nom de famille" error= {errors.lastname} value={functions.capitalize(user.lastname)} onChange={handleChange}></Field>
                        
                </form>*/}
            </div>
        </>
     );
}
 
export default MyAccount;