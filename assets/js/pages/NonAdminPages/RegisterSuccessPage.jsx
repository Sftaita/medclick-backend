import React from 'react';
import { Link} from "react-router-dom";
import '../Styles/registerSuccess.css';
import InfoIcon from '@material-ui/icons/Info';

const RegisterSuccessPage = (props) => {
    return ( 
        <>
            

            <div className="blank">
                <div className="custom-title">Inscription réussie</div>
            
                <p className="lead">Nous avons bien enregistré votre inscription. Activez maintenant votre compte en suivant le lien dans l'email que nous vous avons envoyé.</p>
                <div className="alert alert-info" role="alert">
                   <InfoIcon /> Vous n'avez pas reçu d'email? Vérifiez vos courriers indésirables.
                </div>
                <hr className="my-4" />
                    
                <p className="custom-container-center">
                <Link to={"/login"} className="btn btn-success">Connexion</Link>
                <Link to={"/"} className="btn btn-link">Retour</Link>
                </p>
            </div>
           

        </>
     );
}
 
export default RegisterSuccessPage;