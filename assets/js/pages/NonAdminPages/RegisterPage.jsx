import React, { useState, useEffect} from 'react';
import Field from "../components/Forms/Field";
import Select from "../components/Forms/Select";
import { Link } from "react-router-dom";
import userAPI from "../services/userAPI";
import functions from "../functions/functions";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";
import '../Styles/RegisterPage.css';

const RegisterPage = ({ history}) => {

    const [loading,setLoading] = useState(false);

    const [user,setUser] = useState({
        firstname:"",
        lastname: "",
        email:"",
        speciality:"",
        password:"",
        passwordConfirm:"",
    });

    const [errors,setErrors] = useState({
        firstname:"",
        lastname: "",
        email:"",
        speciality:"",
        password:"",
        passwordConfirm:""
    });

    const [condition,setCondition] = useState({
        condition: false
    });

    //Gestion des champs
    const handleChange = ({currentTarget}) => {
    const value = currentTarget.value;
    const name = currentTarget.name;

    setUser({...user, [name]:value})
    }


    const handleSubmit = async event => {
        event.preventDefault();
        
        const apiErrors = {};
        if(user.password !==user.passwordConfirm){
            apiErrors.passwordConfirm = "Les mots de passe renseignés sont diffèrent";
            setErrors(apiErrors);
            return;
        }


        try {
            setLoading(true);
            const response = await userAPI.create(user);
            setErrors({});
            history.replace('/registerSuccess'); 

        }catch (error) {
            setLoading(false);
            console.log(error.response);

            const { violations } = error.response.data;

            if(violations) {
                
                violations.forEach(violation => {
                    apiErrors[violation.propertyPath] = violation.message
                });
                setErrors(apiErrors);
            }

        }
    }

    const handleCheck = ({currentTarget}) => {
        const {name, checked} = currentTarget;
        setCondition({ ...condition, [name]: checked});

    };

    const [show,setShow] = useState(false);

    const open = () => {
        setShow(true);
    }
    const close = () => {
        setShow(false);
    }
    
    return ( 
        <>
        <div className="form-content">
            {loading && < SpinnerLoader/>}

            {!loading && <div>
                <h1>Insciption</h1>

                <form onSubmit={handleSubmit}>
                    <Field name="firstname" label="Prénom" placeholder="Quel est votre prénom" error= {errors.firstname} value={functions.capitalize(user.firstname)} onChange={handleChange}></Field>

                    <Field name="lastname" label="Nom" placeholder="Quel est votre nom de famille" error= {errors.lastname} value={functions.capitalize(user.lastname)} onChange={handleChange}></Field>

                    <Field name="email" label="Adresse email" type="email" placeholder="Quel est votre email" error= {errors.email} value={user.email} onChange={handleChange}></Field>

                    <Select name="speciality" label="Filière de formation"  value={user.speciality} error={errors.speciality} onChange={handleChange}>
                        <option value=""></option>
                        <option value="general">Chirurgie générale</option>
                        <option value="ortho">Orthopédie</option>
                        <option value="uro">Urologie</option> 
                        <option value="other">Autre</option>               
                    </Select>

                    <Field name="password" label="Mot de passe" type="password" placeholder="Choisir un mot de passe" error= {errors.password} value={user.password} onChange={handleChange}></Field>

                    <Field name="passwordConfirm" label="Confirmation du mot de passe" type="password" placeholder="Confirmer le mot de passe" error= {errors.passwordConfirm} value={user.passwordConfirm} onChange={handleChange}></Field>

                    <div className="form-check">
                        <input className="form-check-input" type="checkbox" value="" id="defaultCheck1" name="condition" checked={condition.condition} onChange={handleCheck}/>
                        <label className="form-check-label">
                            J'accepte les <span onClick={open}>conditons d'utilisations</span> .
                        </label>
                    </div>

                    {show && 
                    <div className="boite">
                        <div className="smallbox">
                            <h1>Conditions génrales</h1>
                            <div className="scroll">
                                <h3>Données personnelles. Respect du Règlement Général de Protection des Données (GDPR).</h3><br/>
                                <p><h4>a) Utilisations des données:</h4><br/>
                                Toutes les données personnelles fournies par le visiteur ou membre sont destinées à l'usage interne de l’application MED-CLICK. Nous nous engageons  à utiliser exclusivement les données qui sont indispensables à une qualité de service optimale.</p>
                                <p><h4>b) Informations conllectées:</h4><br/>
                                    Lors de votre visite sur le site <span>www.medclick.be</span>, nous sommes susceptibles de collecter les informations suivantes :<br/>
                                        - votre 'domain name' (adresse IP) ;<br/>
                                        - votre adresse e-mail lorsque vous envoyez des messages/questions sur le site ou lorsque vous communiquez avec MED-CLICK  par e-mail ;<br/>
                                        - l'ensemble de l'information concernant les pages que vous avez consultées sur le site de MED-CLICK ;<br/>
                                        - toute information que vous nous avez donnée volontairement (par exemple dans le cadre d'enquêtes d'informations et/ou des inscriptions sur site).<br/><br/>
                                    Ces informations sont utilisées pour :<br/>
                                        -  l’application des algorithmes de notre site web ;<br/>
                                        - améliorer le contenu de notre site web ;<br/>
                                        - personnaliser le contenu et le lay-out de nos pages pour chaque visiteur individuel ;<br/>
                                        - vous informer des mises à jour de notre site ;<br/>
                                        - vous aviser d’informations utiles si nécessaire ;<br/>
                                        - vous contacter ultérieurement à des fins de marketing direct.<br/>
                                </p>
                                
                                <p>
                                <h4>c) Cookies et sécurité:</h4><br/>
                                Le site <span>www.medclick.be</span> utilise des cookies, à savoir de petits fichiers déposés sur le disque dur de votre ordinateur qui conservent des informations en vue d'une connexion ultérieure.
                                Ces cookies permettent à MED-CLICK d’enregistrer vos préférences et d’améliorer ou accélérer vos prochaines visites du site.
                                MED-CLICK utilise des technologies de cryptage qui sont reconnues comme les standards industriels dans le secteur, quand elle collecte et utilise vos données personnelles.
                                </p>
                                <p>
                                <h4>d) Moyens de communication.</h4><br/>
                                Si vous nous communiquez votre adresse e-mail via le web, vous pouvez recevoir des e-mails de notre société afin de vous communiquer des informations sur nos produits, services ou événements à venir (dans un but de marketing direct), à la condition que vous y ayez expressément consenti ou que vous soyez déjà membre chez nous et que vous nous ayez communiqué votre adresse e-mail.
                                Si vous ne souhaitez plus recevoir de tels e-mails, vous pouvez en faire la demande de plusieurs manières possibles :<br/>
                                - soit cliqué sur le lien de désinscription qui se trouve en bas de chaque e-mail de promotion ou marketing.<br/>
                                - soit envoyez-nous un e-mail à <span>medclick.info@gmail.be</span><br/>
                                </p>

                                <p>
                                <h4>e) Nouvelles utilisations</h4><br/>
                                MED-CLICK pourrait utiliser les informations des visiteurs ou clients pour de nouvelles utilisations qui ne sont pas encore prévues dans la politique « données personnelles ».
                                Dans cette hypothèse, MED-CLICK  contactera préalablement les membres concernés et leur offrira la possibilité de refuser de participer à ces nouveaux usages.
                                </p>

                                <p>
                                <h4>f) Droit d’information, de correction et droit à l’oubli.</h4><br/>
                                Sur requête, nous procurons aux visiteurs de notre site un accès à toutes les informations les concernant.
                                Par ailleurs, conformément au Règlement européen de protection des données (GDPR), tout visiteur ou membre peut obtenir gratuitement la rectification, la limitation, la suppression ou l’interdiction d’utilisation de toute donnée à caractère personnel le concernant.
                                Si le visiteur ou membre souhaite introduire une telle demande, merci d’envoyer un e-mail à <span>medclick.info@gmail.be</span>.
                                MED-CLICK s’engage à traiter votre demande dans un délai d’un mois.
                                Si vous estimez que notre site ne respecte pas notre police vie privée telle qu'elle est décrite, veuillez prendre contact avec :<br/>
                                - MED-CLICK, dont les coordonnées sont reprises ci-dessus.
                                </p>
                            </div>  

                            <div className="form-group">
                                <button className="btn btn-success" onClick={close}>Continuer</button> 
                            </div>
                            
                        </div>   
                    </div>}

                    {!show &&
                        <div className="form-group">
                        <button type="submit" className="btn btn-success" onClick={close} disabled={!condition.condition && true}>Valider</button> 
                        <Link to="/login" className="btn btn-link">J'ai déjà un compte</Link>
                        </div>
                    }

                </form>
            </div>}
        </div>
        </>
    );
}
 
export default RegisterPage;