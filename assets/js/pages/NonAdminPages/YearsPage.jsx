import React, { useEffect, useState } from "react";
import yearsAPI from "../../services/yearsAPI";
import moment from "moment";
import { Link } from "react-router-dom";
import ExcelButton from "../../components/Buttons/ExcelButton";

const YearsPage = (props) => {
  const [years, setYears] = useState([]);
  const [firstUse, setFirstUse] = useState(false);

  const YEARS_LABELS = {
    1: "Première",
    2: "Deuxième",
    3: "Troisième",
    4: "Quatrième",
    5: "Cinquième",
    6: "Sixième",
    7: "Septième",
    8: "Huitième",
  };

  // Recherche des interventions
  const fetchYears = async () => {
    try {
      const data = await yearsAPI.findAll();
      setYears(data);

      if (data.length == 0) {
        setFirstUse(true);
      }
    } catch (error) {
      console.log(error.response);
    }
  };

  // Au chargement du composant, recherche les années de formations.
  useEffect(() => {
    fetchYears();
  }, []);

  //Formataur de date
  const formatDate = (str) => moment(str).format("DD/MM/YYYY");

  const [excelId, setExcelId] = useState({
    excel: "",
  });

  //Gestion des champs
  const excelChange = ({ currentTarget }) => {
    const value = currentTarget.value;
    const name = currentTarget.name;
    setExcelId({ ...excelId, [name]: value });
  };

  useEffect(() => {
    if (excelId.excel !== "") {
      excel();
    }
  }, [excelId.excel]);

  const [downloaded, setDownloaded] = useState(false);

  const excel = async () => {
    try {
      setDownloaded(true);
      setOpen(true);
      const data = yearsAPI.excel(excelId.excel, () => setDownloaded(false));
    } catch (error) {
      console.log(error.response);
      setDownloaded(false);
    }
  };

  // Toast
  const [open, setOpen] = useState(false);

  return (
    <>
      <div className="form-content">
        <div className="mb-2 d-flex justify-content-between align-items-center">
          <h1> Années de formation </h1>
          <Link to="/years/new" className="btn btn-success">
            Ajouter
          </Link>
        </div>

        <table className="responsive-table">
          <thead>
            <tr>
              <th>Année de formation</th>
              <th>Date de début</th>
              <th>Hopital</th>
              <th>Maitre de stage</th>
              <th></th>
              <th></th>
              <th></th>
            </tr>
          </thead>

          {(!firstUse && (
            <tbody>
              {years.map((year) => (
                <tr key={year.id}>
                  <td data-label="Année">
                    {YEARS_LABELS[year.yearOfFormation]}
                  </td>
                  <td data-label="Début de stage">
                    {formatDate(year.dateOfStart)}
                  </td>
                  <td className="justify" data-label="Hopital">
                    {year.hospital}
                  </td>
                  <td data-label="Maître de stage">Dr {year.master}</td>
                  <td className="center">
                    <Link
                      to={"/years/" + year.id}
                      className="btn btn-sm btn-warning"
                    >
                      Modifier
                    </Link>
                  </td>
                  <td className="center">
                    <Link to={"/surgeons/"} className="btn btn-sm btn-success">
                      Superviseurs
                    </Link>
                  </td>
                  <td className="center">
                    {(!downloaded && (
                      <ExcelButton
                        label="Excel"
                        id={year.id}
                        name="excel"
                        value={year.id}
                        onChange={excelChange}
                      />
                    )) || <div className="spinner-border"></div>}
                  </td>
                </tr>
              ))}
            </tbody>
          )) || (
            <tbody>
              <tr>
                <td colSpan="7">
                  {" "}
                  Vous n'avez pas encore d'année enregistrées.
                </td>
              </tr>
            </tbody>
          )}
        </table>
      </div>
    </>
  );
};

export default YearsPage;
