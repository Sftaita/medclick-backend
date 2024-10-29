import React from 'react';
import './Modal.css'

const Modal = ({titre, body, show, close, search}) => {

       
     

    return ( 
        <>
        
            <div className="modal-wrapper"
                style= {{
                    
                    opacity: show ? '1' : '0'
                }}
            >
                <div className="modal-header">
                    <p>{titre}</p>
                    <span onClick={close} className="close-modal-btn">X</span>
                </div>
                <div className="modal-content">
                    <div className="modal-search">
                        {search}
                    </div>
                    <div className="modal-body">
                        {body}
                    </div>
                    <div className="modal-footer">
                            <button onClick={close} className="btn btn-danger">Fermer</button>
                    </div>
                </div>
            </div>
        
        </>
     );
}
 
export default Modal;