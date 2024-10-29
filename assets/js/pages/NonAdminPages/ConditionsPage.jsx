import React from 'react';
import { Link} from "react-router-dom";
import '../Styles/conditionsPage.css';

const ConditionsPage = (props) => {
    return (  
        <>
            <div className="inside">
                            <h1>Conditions génrales</h1>
                            
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
                            

                            <div className="form-group">
                                <Link to={"/"} className="btn btn-success">Retour</Link>
                            </div>
                            
                        </div>   
        </>
    );
}
 
export default ConditionsPage;