import React from 'react';
import Container from "../components/Container/Container";

import raccourci from '../images/raccourci.png';
import favoris from '../images/favoris.png';
import ecran from '../images/ecran.png';
import securite from '../images/securite.png';
import advice from '../images/advice.png';


const TricksPage = (props) => {
    return ( 
        <>

            <Container 
                title="Ajout rapide" 
                text="Il est possible de revenir à la page d’ajout rapide en cliquant sur le logo med-click." 
                image={raccourci} 
                color="primary"
            />    

            <Container 
                title="Favoris" 
                text= "En ajoutant des favoris, tu gagnes du temps en ayant sous la main toute les interventions que tu réalises régulièrement. Personnalise leurs noms afin de les trouver faciles." 
                image={favoris} 
                color="secondary"
            /> 

            <Container 
                title="Mobile App like" 
                text="Ressentir l'expérience d'une application mobile. Pour ce faire, enregistre la page d’accueil Med-click sur l’écran d’accueil de ton smartphone." 
                image={ecran} 
                color="primary"
            /> 

            <Container 
                title="Sécurité" 
                text="Veille à réaliser régulièrement  des sauvegardes en important ton Excel." 
                image={securite} 
                color="secondary"
            /> 

            <Container 
                title="Régularité" 
                text="Tu n’as plus envie de passer plusieurs semaines à encoder ton carnet de stage ? Essai d’encoder tes prestations le plus fréquemment possible. Ainsi, il te faudra moins d’une minute pour rendre ton prochain carnet de stage l'an prochain." 
                image={advice} 
                color="primary"
            />  

        </>
     );
}

export default TricksPage;