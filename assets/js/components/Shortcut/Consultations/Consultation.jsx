import React from 'react';
import './Consultation.css'
import Field from "../../Forms/Field";

const Consultation = (value, handleChange, onClick) => {
    return ( 



        <>
            <div className="personal-modal">
                <h1>Ajouter des consultations</h1>

                <div className="inside-personal-modal">
                    <Field name="date" label="Date" value={value} onChange={onChange} placeholder= "Date" error = "" onClick={onClick}/>
                    <Field name="number" label="Nombre" value={value} onChange={onChange} placeholder= "Nombre de conultation" error = ""/>
                </div>
            </div>

        </>
     );
}
 
export default Consultation;