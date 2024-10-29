import React from 'react';
import './Container.css'

const Container = ({title, text, image, color}) => {
    return ( 
        <>
            <div className="cover">
                <div className={color}>
                    <div className="text">
                        <h3>{title}</h3>
                        
                        <p>{text}</p>
                    </div>
                    
                    <div className="img">
                        <img src={image} className="img-fluid" alt="Responsive image"/>  
                    </div>
                    
                </div>
                    

            </div>
        </> 
    );
}
 
export default Container;