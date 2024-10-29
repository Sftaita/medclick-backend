import React from 'react';
import './SpinnerLoader.css'

const SpinnerLoader = (props) => {
    return ( 

        <>
        <div className="spinner">
            <div className="d-flex justify-content-center align-items-center">
                <div className="spinner-border" role="status">
                    <span className="sr-only">Loading...</span>
                </div>
            </div>
        </div>    
        </>
     );
}
 
export default SpinnerLoader;