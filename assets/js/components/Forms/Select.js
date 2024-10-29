import React, { PureComponent } from 'react';

const Select = ({name, value, error="", label, onChange, children, onClick}) => {
    return ( 

        <div className="form-group">
            <label htmlFor={name}>{label}</label>
            <select onChange={onChange} onClick= {onClick} name={name} id={name} value={value} className={"form-control" +(error && " is-invalid")}>
                {children}
            </select>

            <p className="invalid-feedback">{error}</p>
        </div>

     );
}
 
export default Select;