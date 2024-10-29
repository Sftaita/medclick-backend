import React, { useEffect, useState } from 'react';
import '../Styles/frontPage.css';
import { Link } from 'react-router-dom';
import yearsAPI from "../services/yearsAPI";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";

import Badge from '@material-ui/core/Badge';

import Intervention from '../images/Intervention.png';
import Consultation from '../images/Consultation.png';
import Garde from '../images/Garde.png';
import Formation from '../images/Formation.png';
import Superviseur from '../images/Superviseur.png';
import Year from '../images/Year.png';
import add from '../images/add.png';
import statisticsAPI from '../services/statisticsAPI';



const FrontPage = (props) => {


    const [loading,setLoading] = useState(true);
    
        // 1. Vérifier que l'utilisateur à au moins une année enregitrer.
        const [firstUse, setFirstUse] = useState(false);

         
        const fetchData = async () => {
        try{

            //   On recherche les années de l'utilisateur :

            const data = await yearsAPI.findAll();
            const count = ((data).length);
               
            
            if(count == 0)
            {
                setFirstUse(true);
                
            }else{
                
            }
            setLoading (false)
        }       
        catch(error){   
        }}

    
        // Au chargement du composant, une recherche des interventions se lance :

            useEffect(()=>{ fetchData() }, [] )

    
    return ( 
        <>  
            
                {loading && <SpinnerLoader/>}  


                {!loading && (<div className="main-container">
                        
                {!firstUse && (
                    
                    <div className="grid container">

                            <Link to={"/surgeries/new"} className="a">
                                    <div className="more-info"><div className="b">+</div></div>
                                    <div className="card-container"> 
                                        <img src={Intervention} className="d"/>
                                        <h4>Intervention</h4>        
                                    </div>                                               
                            </Link>
                        
                            <Link to={"/consultations/new"}>
                                    <div className="more-info"><div className="b">+</div></div>
                                    <div className="card-container"> 
                                        <img src={Consultation} className="d"/>
                                        <h4>Consultation</h4>
                                    </div>       
                            </ Link>
                      
                            <Link to={"/gardes/new"}>
                                <div className="more-info"><div className="b">+</div></div>
                                <div className="card-container"> 
                                        <img src={Garde} className="d"/>
                                        <h4>Garde</h4>
                                </div>
                            </ Link>
                        
                            <Link to={"/formations/new"}>
                                <div className="more-info"><div className="b">+</div></div>
                                <div className="card-container"> 
                                        <img src={Formation} className="d"/>
                                        <h4>Formation</h4>
                                </div>  
                            </ Link>
                     

                            <Link to={"/surgeons"}>

                                <div className="card-container"> 
                                        <img src={Superviseur} className="d"/>
                                        <h4>Superviseurs</h4>
                                </div>

                            </ Link>

                            <Link to={"/years"}>
                                <div className="card-container"> 
                                        <img src={Year} className="d"/>
                                        <h4>Année</h4>
                                </div>
                            </Link> 
                    </div>
                )}

                {firstUse && (
                    
                    <div className="new-box">
                        <div className="text">
                            <h1>Bonjour</h1>
                            <p>Tu n'a pas encore enregistré d'année de formation. Pour encoder tes données, <span>enregistre une année</span>.</p><Link to={"/years/new"} />
                            <Link to={"/years/new"} className="btn btn-outline-success">Ma première année</Link> 
                        </div>
                        <div className="image">
                            <img src={add} />
                        </div>
                        
                    </div>
                        
                    
                )}
            </div>)}
        

        </>

     );
}
 
export default FrontPage;