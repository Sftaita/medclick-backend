
/**
* Permet de mettre la première lettre en majuscule
* @param sting Le mot à capitaliser
*/
const capitalize = (string) => {
    return string.charAt(0).toUpperCase() + string.slice(1);
}


export default {
    capitalize,
}