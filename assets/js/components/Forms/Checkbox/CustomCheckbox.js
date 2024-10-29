import React from 'react';
import './CustomCheckbox.css'

const CustomCheckbox = ({name, value, label, onChange, id}) => {
    return ( 

        <div className="box">
            
            <label htmlFor={id} className="radio">
                {label}
            </label>
           <input type="radio" name={name} value={value} id={id} onChange={onChange} className="radio__input"/> 
        </div>

     );
}
 
export default CustomCheckbox;