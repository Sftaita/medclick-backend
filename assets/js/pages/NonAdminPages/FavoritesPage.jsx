import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import favoritesAPI from "../services/favoritesAPI";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";

const FavoritesPage = (props) => {

    const SPECIALITY_LABELS = {
        ortho: "Orthopédie",
        vasc: "Vaculaire",
        uro: "Urologie",
        plastic: "Chir plastique",
        dig: "Digestive",   
        general: "Générale",  
        thor: "Thoracique", 
        transp: "Transplantation", 
        neuro: "Neurochirurgie", 
    }

    // Initialisation :

            const [loading,setLoading] = useState(true);
            const [noFavorite, setNoFavorite] = useState(false);
    

    // Recherche des favoris

            const [favorites, setFavorites] = useState([]);
            const fetchFavorites = async () => {
                try {
                    const data = await favoritesAPI.fetchAll() 
                    
                    if(data.length == 0) {
                        setNoFavorite(true);
                        setLoading(false)
                    }else{
                        setFavorites(data);
                        setLoading(false) 
                    }
                    
                } catch (error) {
                    console.log(error.response)
                }
            }
            useEffect(()=>{ fetchFavorites(); },[])
            


    //Fonction de suppression
    const handleDelete = async id => {
        const originalFavorites = [...favorites];
        
        setFavorites(favorites.filter(favorites => favorites.id !== id));

        try{
            await favoritesAPI.deleteFavorite(id)
        } catch(error) {
            setFavorites(originalFavorites);
        }   
    };
    return ( 
    <>
    <div className="form-content">

        <div className="mb-2 d-flex justify-content-between align-items-center">
            <h1>Mes favoris</h1> 
            <Link to="/favorites/new" className="btn btn-success">Ajouter</Link>
        </div>

        {loading && <SpinnerLoader/>}
        
        {!loading &&<table className="responsive-table">
            <thead>
                <tr>
                    <th>Nom du favoris</th>
                    <th>Nom de l'intervention</th>
                    <th>Specialité</th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>


            {!noFavorite && 
                <tbody>
                    {favorites.map(favorite => (
                        <tr key={favorite.id}>
                            <td data-label="Nom du favoris">{favorite.shortcut}</td>
                            <td data-label="Nom de l'intervention">{favorite.SurgeryName}</td>
                            <td data-label="Specialité">{SPECIALITY_LABELS[favorite.speciality]}</td>
                            <td className="center"><Link to={"/favorites/" + favorite.id} className="btn btn-sm btn-warning">Modifier</Link></td>
                            <td className="center"><button onClick={() => handleDelete(favorite.id) }className="btn btn-sm btn-danger">Supprimer</button></td>
                        </tr> 
                    ))}       
                </tbody> 
            
            ||
            
                <tbody>
                    <tr >
                        <td colSpan="5"> Vous n'avez pas encore de favoris. Cliquer sur ajouter pour constituer votre liste de favoris.</td>   
                    </tr>   
                </tbody>
            }
                            
        </table>} 
    </div>    
    </> 
);}
 
export default FavoritesPage;