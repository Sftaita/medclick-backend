import React from 'react';
import './GroupButtons.css'

const GroupButtons = ({ label , sendNumber, repetition}) => {
    return ( 
        <>
            <div className="form-group">
                            <label>{label}</label>
                            <div className="w-100 p-3">
                            <div className="btn-group" role="group">
                                <button key="1" type="button" className={"btn btn-secondary" + (repetition == 1 && " active")}  onClick={() => sendNumber(1)}>1x</button>
                                <button key="2" type="button" className={"btn btn-secondary" + (repetition == 2 && " active")}  onClick={() => sendNumber(2)}>2x</button>
                                <button key="3" type="button" className={"btn btn-secondary" + (repetition == 3 && " active")}  onClick={() => sendNumber(3)}>3x</button>
                                <button key="4" type="button" className={"btn btn-secondary" + (repetition == 4 && " active")}  onClick={() => sendNumber(4)}>4x</button>
                            </div>  
                            </div>
            </div>
        </>
     );
}
 
export default GroupButtons;