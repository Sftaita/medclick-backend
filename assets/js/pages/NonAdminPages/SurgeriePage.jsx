import React, { useEffect, useState, useContext } from "react";
import AuthContext from "../../contexts/AuthContext";
import { Link } from "react-router-dom";
import Field from "../../components/Forms/Field";
import Modal from "../../components/Modal/Modal";
import CustomCheckbox from "../../components/Forms/Checkbox/CustomCheckbox";
import surgeriesAPI from "../../services/surgeriesAPI";
import moment from "moment";
import Select from "../../components/Forms/Select";
import yearsAPI from "../../services/yearsAPI";
import nomenclatureAPI from "../../services/nomenclatureAPI";
import surgeonsAPI from "../../services/surgeonsAPI";
import SpinnerLoader from "../../components/SpinnerLoader/SpinnerLoader";
import favoritesAPI from "../../services/favoritesAPI";
import GroupButtons from "../../components/GroupButtons/GroupButtons";

import "date-fns";
import DateFnsUtils from "@date-io/date-fns";

const SurgeriePage = ({ match, history }) => {
  // Initialisation :

  const { id = "new" } = match.params;
  const [loading, setLoading] = useState(true);
  const [loadingModal, setLoadingModal] = useState(true);
  const [editing, setEditing] = useState(false);
  const [favoriteMode, setFavoriteMode] = useState(false);
  const [noFavorite, setNoFavorite] = useState(false);
  const [repetition, setRepetition] = useState(1);

  const YEARS_LABELS = {
    1: "Première année",
    2: "Deuxième année",
    3: "Troisième année",
    4: "Quatrième année",
    5: "Cinquième année",
    6: "Sixième année",
    7: "Septième année",
    8: "Huitième année",
  };

  const [year, setYear] = useState([]);
  const [surgeons, setSurgeons] = useState([]);

  const [surgery, setSurgery] = useState({
    date: "",
    speciality: "",
    name: "",
    position: "1",
    year: "",
    firstHand: "",
    secondHand: "",
  });
  const [errors, setErrors] = useState({
    date: "",
    speciality: "",
    name: "",
    position: "",
    firstHand: "",
    secondHand: "",
  });

  console.log(surgery);
  // Date picker :

  const [selectedDate, setSelectedDate] = useState();
  const [isOpen, setIsOpen] = useState(false);
  const handleDateChange = (date) => {
    setSelectedDate(date);
  };

  useEffect(() => {
    setSurgery({
      ...surgery,
      date: `${moment(selectedDate).format("DD-MM-YYYY")}`,
    });
  }, [selectedDate]);

  //------------------  Chargement au démarrage ------------------//

  // Cherche les années de formation disponible de l'utilisateur.
  useEffect(() => {
    fetchYear();
  }, []);

  useEffect(() => {
    if (surgery.year !== "") {
      surgeonsList();
    }
  }, [surgery.year]);

  // Chargement de l'intervention si besoin au chargement du composant ou au changement de l'identifiant.
  useEffect(() => {
    if (id !== "new") {
      setEditing(true);
      fetchSurgery(id);
    }
  }, [id]);

  // Récupération de l'intervention selon l'ID
  const fetchSurgery = async (id) => {
    try {
      const data = await surgeriesAPI.find(id);
      const { date, speciality, name, position, year, firstHand, secondHand } =
        data;
      setSurgery({
        date: moment(date).format("DD-MM-YYYY"),
        speciality,
        name,
        position,
        firstHand,
        secondHand,
        year: `${data.year.id}`,
      });
      setLoading(false);
    } catch (error) {
      history.replace("/surgeries");
    }
  };

  //Gestion des champs
  const handleChange = ({ currentTarget }) => {
    const value = currentTarget.value;
    const name = currentTarget.name;

    setSurgery({ ...surgery, [name]: value });
    closeModalHandler();
  };

  //Recherche les année de formation de l'utilisateur.
  const fetchYear = async () => {
    try {
      const data = await yearsAPI.findAll();
      setYear(data);

      if (data.length == 0) {
        history.replace("/home");
      }

      // Au chargement, transmet l'année de la dernière année de fomation en date à la year.
      if (id === "new") {
        setSurgery({ ...surgery, year: `${data[0].id}` });
      }
    } catch (error) {
      console.log(error.response);
    }
  };

  // Recherche la liste des superviseurs disponible pour l'année.
  const surgeonsList = async () => {
    try {
      const data = await surgeonsAPI
        .findSurgeons(surgery.year)
        .then((response) => response.data);
      setSurgeons(data);
      setLoading(false);
    } catch (error) {
      console.log(error.response);
    }
  };

  console.log(surgery);
  //Gestion de la soumission du formulaire.

  const handleSubmit = async (event) => {
    event.preventDefault();
    const apiErrors = {};

    if (surgery.date === "") {
      apiErrors.date = "Veuillez indiquer la date.";
      setErrors(apiErrors);
      return;
    }

    if (surgery.position == 2 && surgery.firstHand === "") {
      apiErrors.firstHand = "Veuiller renseigner la première main";
      setErrors(apiErrors);
      return;
    }

    if (surgery.position == 3 && surgery.secondtHand === "") {
      apiErrors.secondHand = "Veuiller renseigner la deuxième main";
      setErrors(apiErrors);
      return;
    }

    {
      /* 
        if(surgery.position == 2){
            setSurgery.secondHand = "";
        }
        
        if(surgery.position == 3){
            setSurgery.firstHand = "";
        }*/
    }

    try {
      if (editing) {
        setSurgery();
        surgeriesAPI.update(id, {
          ...surgery,
          year: `/api/years/${surgery.year}`,
          date: `${moment(surgery.date, "DD-MM-YYYY").format("YYYY-MM-DD")}`,
        });
        history.replace("/surgeries");
      } else {
        setLoading(true);
        for (let i = 1; i <= repetition; i++) {
          const response = await surgeriesAPI.create({
            ...surgery,
            year: `/api/years/${surgery.year}`,
            date: `${moment(surgery.date, "DD-MM-YYYY").format("YYYY-MM-DD")}`,
          });
        }
        history.replace("/home");
      }
      setErrors({});
    } catch ({ response }) {
      const { violations } = response.data;
      if (violations) {
        const apiErrors = {};
        violations.forEach(({ propertyPath, message }) => {
          apiErrors[propertyPath] = message;
        });
        setErrors(apiErrors);
        setLoading(false);
      }
    }
  };

  //--------------   Gestion de choix de spédcialité ---------------------------//
  const [speciality, setSpeciality] = useState({
    speciality: "",
  });

  //  Gestion des champs :

  const handleChangeSpeciality = ({ currentTarget }) => {
    const value = currentTarget.value;
    const name = currentTarget.name;

    setSpeciality({ ...speciality, [name]: value });
    setSurgery({ ...surgery, [name]: value });
  };

  //Ouverture du Modal et gestion des proprosition d'intervention.
  //************************************************************************************/

  // Gestion de l'ouverture du Modal :

  const [show, setShow] = useState(false);
  const openModalHandler = () => {
    if (speciality.speciality !== "") {
      setShow(true);
    }
  };

  const closeModalHandler = () => setShow(false);

  // Lorsqu'une spécialité est sélectionner, ouverture de la fenêtre des choix et recheche des interventions de la spécialité :

  useEffect(() => {
    if (speciality.speciality !== "") {
      setShow(true);
      fetchSpe();
      setLoadingModal(true);
    }
  }, [speciality]);

  // Contient la liste de nomenclature brute.
  const [list, setList] = useState([]);
  const [favList, setFavList] = useState([]);

  /**
   * Permet de récuper l'ensemble des propositions d'intervention d'une spécialité.
   */
  const fetchSpe = async () => {
    try {
      if (speciality.speciality === "favorites") {
        setFavoriteMode(true);
        const response = await favoritesAPI.fetchAll();
        setFavList(response);
        if (response.length == 0) {
          setNoFavorite(true);
        }
      } else {
        setFavoriteMode(false);
        const response = await nomenclatureAPI.fetch(speciality.speciality);
        setList(response.data);
      }
      setLoadingModal(false);
    } catch (error) {
      console.log(error.response);
    }
  };

  // Gestion de la recherche :

  const [search, setSearch] = useState("");
  const handleSearch = (event) => {
    const value = event.currentTarget.value;
    setSearch(value);
  };

  // Contient la liste de nomenclature filtrer :

  const filteredSearch = list.filter(
    (l) =>
      l.name.toLowerCase().includes(search.toLowerCase()) ||
      l.codeAmbulant.toLowerCase().includes(search.toLowerCase()) ||
      l.codeHospitalisation.toLowerCase().includes(search.toLowerCase())
  );

  //*********************************************************************************//

  // Gestion de la pagination :

  const [page, setPage] = useState();

  return (
    <>
      <div className="form-content">
        {(!editing && <h1>Ajouter une intervention</h1>) || (
          <h1>Modifier une intervention</h1>
        )}

        {loading && <SpinnerLoader />}

        {!loading && (
          <form onSubmit={handleSubmit}>
            <Field
              name="date"
              label="Date"
              placeholder={"Date de l'intervention"}
              value={surgery.date}
              onClick={() => setIsOpen(true)}
              onChange={handleChange}
              error={errors.date}
            ></Field>

            <MuiPickersUtilsProvider utils={DateFnsUtils}>
              <KeyboardDatePicker
                value={selectedDate}
                onChange={handleDateChange}
                disableFuture
                format="dd/mm/yyyy"
                margin="normal"
                TextFieldComponent={() => null}
                open={isOpen}
                onOpen={() => setIsOpen(true)}
                onClose={() => setIsOpen(false)}
              />
            </MuiPickersUtilsProvider>

            <Select
              name="year"
              label="Année de formation"
              value={surgery.year}
              error={errors.year}
              onChange={handleChange}
            >
              {year.map((y) => (
                <option key={y.id} value={y.id}>
                  {YEARS_LABELS[y.yearOfFormation]}
                </option>
              ))}
            </Select>

            <Select
              name="speciality"
              label="Spécialité"
              value={speciality.speciality}
              error=""
              onChange={handleChangeSpeciality}
            >
              <option value="">Choisir une spécialité</option>
              <option className="gold" value="favorites">
                Mes favoris
              </option>
              <option value="ortho">Chirurgie orthopédique</option>
              <option value="dig">Chirurgie digestive</option>
              <option value="general">Chirurgie générale</option>
              <option value="uro">Chirurgie urologique</option>
              <option value="vasc">Chirurgie vasculaire</option>
              <option value="thor">Chirurgie thoracique</option>
              <option value="plastic">Chirurgie plastique</option>
              <option value="neuro">Neurochirurgie</option>
              <option value="transp">Transplantation</option>
            </Select>

            {speciality.speciality !== "" && (
              <Field
                name="name"
                label="Intervention"
                placeholder="Nom d'intervention"
                value={surgery.name}
                onChange={handleChange}
                error={errors.name}
                onClick={openModalHandler}
              ></Field>
            )}

            {show ? (
              <div className="back-drop">
                {loadingModal && <SpinnerLoader />}

                {!loadingModal && (
                  <div>
                    {(!favoriteMode && (
                      <div>
                        <Modal
                          show={show}
                          close={closeModalHandler}
                          titre="Sélectionner une intervention"
                          search={
                            <div className="form-group">
                              <input
                                type="text"
                                onChange={handleSearch}
                                value={search}
                                className="form-control"
                                placeholder="Rechercher"
                              />
                            </div>
                          }
                          body={
                            <div className="form-groupe">
                              {filteredSearch.map((x) => (
                                <div key={x.id}>
                                  <CustomCheckbox
                                    label={x.name}
                                    id={x.id}
                                    name="name"
                                    value={x.name}
                                    onChange={handleChange}
                                  />
                                </div>
                              ))}
                            </div>
                          }
                        />
                      </div>
                    )) || (
                      <div>
                        <Modal
                          show={show}
                          close={closeModalHandler}
                          titre="Sélectionner une intervention"
                          search={<div className="form-group"></div>}
                          body={
                            (!noFavorite && (
                              <div>
                                <div className="form-groupe">
                                  {favList.map((x) => (
                                    <div key={x.id}>
                                      <CustomCheckbox
                                        label={x.shortcut}
                                        id={x.id}
                                        name="name"
                                        value={x.SurgeryName}
                                        onChange={handleChange}
                                      />
                                    </div>
                                  ))}
                                </div>
                              </div>
                            )) || (
                              <div>
                                <div className="form-groupe">
                                  <div>
                                    Vous n'avez pas encore enregistré de
                                    favoris.
                                  </div>
                                </div>
                              </div>
                            )
                          }
                        />
                      </div>
                    )}
                  </div>
                )}
              </div>
            ) : null}

            <Select
              name="position"
              label="Position"
              value={surgery.position}
              error={errors.position}
              onChange={handleChange}
            >
              <option value="1">Première main - Solo</option>
              <option value="3">Première main - Accompagné</option>
              <option value="2">Deuxième main</option>
            </Select>

            {(surgery.position == 2 && (
              <div className="div form-group">
                <Select
                  name="firstHand"
                  label="Première main"
                  value={surgery.firstHand}
                  error={errors.firstHand}
                  onChange={handleChange}
                >
                  <option value=""></option>
                  {surgeons.map((surgeon) => (
                    <option key={surgeon.id} value={surgeon.id}>
                      Dr {surgeon.firstName} {surgeon.lastName}
                    </option>
                  ))}
                </Select>
                <Link to="/surgeons" className="btn btn-link pt-0">
                  Ajouter un superviseur
                </Link>
              </div>
            )) ||
              (surgery.position == 3 && (
                <div className="div form-group">
                  <Select
                    name="secondHand"
                    label="Deuxième main"
                    value={surgery.secondHand}
                    error={errors.secondHand}
                    onChange={handleChange}
                  >
                    <option value=""></option>
                    {surgeons.map((surgeon) => (
                      <option key={surgeon.id} value={surgeon.id}>
                        Dr {surgeon.firstName} {surgeon.lastName}
                      </option>
                    ))}
                  </Select>
                  <Link to="/surgeons" className="btn btn-link pt-0">
                    Ajouter un superviseur
                  </Link>
                </div>
              ))}

            {!editing && (
              <GroupButtons
                label="Multiplicateur"
                repetition={repetition}
                sendNumber={(number) => setRepetition(number)}
              />
            )}

            <div className="div form-group">
              <button type="submit" className="btn btn-success">
                Enregistrer
              </button>
              <Link to="/surgeries" className="btn btn-link">
                {" "}
                Retour
              </Link>
            </div>
          </form>
        )}
      </div>
    </>
  );
};

export default SurgeriePage;
