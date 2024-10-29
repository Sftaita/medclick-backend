import React, { useEffect } from 'react';
import { Link} from "react-router-dom";
import '../Styles/homePage.css';
import AOS from 'aos';
import 'aos/dist/aos.css'; 



import about from '../images/about.png';
import excel from '../images/excel.png';
import calendar from '../images/calendar.png';
import banner from '../images/banner.png';
import front from '../images/front.png';
import pc from '../images/pc.png';
import smartphone from '../images/smartphone.png';

const HomePage = (props) => {

    useEffect(()=>{ AOS.init(); },[])
    return ( 
        <>
            <div className="main">

                <div className="landing">
                    <div className="landingText">
                    <h1>MED-CLICK</h1>
                    <h3>L'application web <span>smart médical</span></h3>
            
                    <hr className="my-4" />
                    <p>Solution pour médecin résident.</p>
                    <Link to={"/login"} className="btn btn-success">Se connecter</Link>
                    <Link to={"/register"} className="btn btn-link">S'inscrire</Link>
                    
                    </div>

                    
                    <div className="img-container">
                        <img className="landingImageOne" src={pc} />
                        <img className="landingImageTwo" src={smartphone} />
                    </div>   
                </div>


                <div className="about">
                    <div className="aboutText" data-aos="fade-up"  data-aos-duration="1000">
                        <h1>Qu'est-ce que <br/> <span>Med-Click ?</span> </h1>
                        <img src={about} />
                    </div>

                    <div className="aboutList">
                        <ol>
                            <li data-aos="fade-left"  data-aos-duration="1000">
                                <span>01</span>
                                <p>Une solution créée par des résidents pour des résidents, afin d'apporter une solution pour la tenue de ton "carnet de stage".</p>
                            </li>

                            <li data-aos="fade-left"  data-aos-duration="1000">
                                <span>02</span>
                                <p>Un moyen de rechercher plus facilement les codes INAMI des interventions que tu réalises.</p>
                            </li>

                            <li data-aos="fade-left"  data-aos-duration="1000">
                                <span>03</span>
                                <p>Un outil capable de générer automatiquement ton carnet de stage au format Excel. Ainsi, il te sera toujours possible de le modifier.</p>
                            </li>

                            <li data-aos="fade-left"  data-aos-duration="1000">
                                <span>04</span>
                                <p>Une application qui te permet de suivre ton évolution au jour le jour.</p>
                            </li>
                        </ol>
                    </div>
                </div>

                <div className="infoSection">
                    <div className="infoHeader" data-aos="fade-up"  data-aos-duration="1000">
                        <h1>Comment cela <span>fonctionne ?</span></h1>
                    </div>

                    <div className="infoCards">
                        <div className="card one" data-aos="fade-up"  data-aos-duration="1000"> 
                            <img src={calendar} className="cardoneImg" data-aos="fade-up"  data-aos-duration="1100"/>
                            <div className="cardbgone"></div>
                            <div className="cardContent">
                                <h2>Tes années de formation</h2>
                                <p>Crée une année de formation et renseignes les superviseurs qui t'encadrent.</p>
                                                               
                                
                            </div>
                        </div>

                        <div className="card two" data-aos="fade-up"  data-aos-duration="1300"> 
                            <img src={front} className="cardtwoImg" data-aos="fade-up"  data-aos-duration="1200"/>
                            <div className="cardbgtwo"></div>
                            <div className="cardContent">
                                <h2>Tes activités</h2>
                                <p>Renseigne tes interventions, tes consultations, tes formations et autres infomations concernant tes activités.</p>
                                <p>L'interface est intuitive et conçue pour perde le moins de temps possible.</p>
                            </div>
                        </div>

                        <div className="card three" data-aos="fade-up"  data-aos-duration="1600"> 
                            <img src={excel} className="cardthreeImg" data-aos="fade-up"  data-aos-duration="1200"/>
                            <div className="cardbgone"></div>
                            <div className="cardContent">
                                <h2>Ton carnet de stage en un click</h2>
                                <p>Rends-toi dans ton gestionnaire d'année pour générer ton Excel.</p>
                                <p>Nous avons choisi le format Excel afin que tu puisses modifier ton carnet de stage si nécessaire.</p>
                            </div>
                        </div>
                    </div>
                        
                </div>
                

                <div className="banner">
                    <div className="bannerText" data-aos="fade-right"  data-aos-duration="1000">
                        <h1>Vos questions</h1>
                        <h3>Est-ce payant?</h3>
                        <p>Nous n'avons pas encore décidé du moyen de financement de l'application (frais de serveur, developeur, designer, ...). Tant que l'application reste dans sa version bêta, elle restera gratuite.</p>

                        <h3>Si l'application devenait payante, pourrais-je toujours accéder à mon profil?</h3>
                        <p>Vous aurez toujours accès de manière gratuite aux informations encodées dans la version bêta.</p>

                        <h3>Que faites-vous des informations que j'enregistre?</h3>
                        <p>Rien ! Elles ne servent qu'à l'élaboration de vos statistiques et ne seront jamais partagées. Seul toi y as accès! (Voir conditions générales).</p>

                        <h3>Comment puis-je vous/nous aider?</h3>
                        <p>N'hésite pas à nous contacter pour rapporter un bug ou proposer une idée afin d'améliorer l'application.</p>
                        
                        <img src={banner} className="bannerImg" data-aos="fade-left"  data-aos-duration="1000"/>
                    </div>
                </div>

                <div className="footer">
                    <h2>MED-CLICK</h2>
                    <div className="footerLinks" >
                        <a href="mailto:medclick.info@gmail.com">Nous contacter</a>
                        <Link to={"/conditions"} className="btn btn-link">Conditions générales</Link>
                    </div>

                </div>
            </div>
        </>
     );
}
 
export default HomePage;