import React, { useState, useEffect } from "react";
import "./StatisticsPage.css";
import statisticsAPI from "../../services/statisticsAPI";
import Select from "../../components/Forms/Select";
import moment from "moment";
import SpinnerLoader from "../../components/SpinnerLoader/SpinnerLoader";

import { Line } from "react-chartjs-2";
import { format } from "date-fns";

const StatisticsPage = (props) => {
  //-----------------------  Mise en place -------------------//

  const [loading, setLoading] = useState(true);
  const [interval, setInterval] = useState("w1");
  const formattedDate = format(new Date(), "yyyy-MM-dd");

  const INTERVAL = {
    "- 1 day": "1 jour",
    "- 2 days": "2 jour",
    "- 3 days": "3 jour",
    "- 4 days": "4 jour",
    "- 5 days": "5 jour",
    "- 6 days": "6 jour",
    "- 1 week": "1 semaine",
    "- 2 weeks": "2 semaines",
    "- 3 weeks": "3 semaines",
    "- 1 month": "1 mois",
    "- 2 months": "2 mois",
    "- 3 months": "3 mois",
    "- 6 months": "6 mois",
  };

  //Gestion des champs :

  const handleChange = ({ currentTarget }) => {
    const value = currentTarget.value;
    setInterval(value);
  };

  // Recherche des utilisateurs :

  const [connectionHistory, setConnectionHistory] = useState([]);

  const fetch = async (value) => {
    try {
      const data = await statisticsAPI.getHistory(value);
      setConnectionHistory(data);
      setLoading(false);
    } catch (error) {
      console.log(error.response);
    }
  };

  useEffect(() => {
    fetch("w1");
  }, []);

  // Fonctions

  /**
   * Permet de rendre unique les objets par propriétée.
   * @param {array} arr Tableau à traiter
   * @param {array} keyProps Tableau des propriété à isolé
   * @returns {array}
   */
  function unique(arr, keyProps) {
    const kvArray = arr.map((entry) => {
      const key = keyProps.map((k) => entry[k]).join("|");
      return [key, entry];
    });
    const map = new Map(kvArray);
    return Array.from(map.values());
  }

  /**
   *
   * @param {array} arr Tableau correspondant à connectionHistory
   * @param {string} key Nom de l'objet recherché.
   * @returns {array}
   */
  function findOcc(arr, key) {
    let arr2 = [];

    arr.forEach((x) => {
      // Checking if there is any object in arr2
      // which contains the key value
      if (
        arr2.some((val) => {
          return val[key] == x[key];
        })
      ) {
        // If yes! then increase the occurrence by 1
        arr2.forEach((k) => {
          if (k[key] === x[key]) {
            k["occurrence"]++;
          }
        });
      } else {
        // If not! Then create a new object initialize
        // it with the present iteration key's value and
        // set the occurrence to 1
        let a = {};
        a[key] = x[key];
        a["occurrence"] = 1;
        arr2.push(a);
      }
    });

    return arr2;
  }

  // Historique des connections
  const [dates, setDates] = useState();
  const [totalNumber, setTotalNumber] = useState();

  const [datesUniq, setDatesUniq] = useState();
  const [totalNumberUniq, setTotalNumberUniq] = useState();

  useEffect(() => {
    working(), uniqUser();
  }, [connectionHistory]);

  const working = () => {
    //const inWaiting = uniqueByKey(connectionHistory, "date");
    //inWaiting.map((x) => dates.push(x.date));
    const var1 = [];
    const var2 = [];

    // Compte le nombre d'occurence par clé date
    const dataCounter = findOcc(connectionHistory, "date");

    // Rempli les tableaus de date et de nombre de connection.
    dataCounter.map(
      (x) => (
        var1.push(moment(x.date).format("DD-MM-YY")), var2.push(x.occurrence)
      )
    );
    setDates(var1);
    setTotalNumber(var2);
  };

  const uniqUser = () => {
    const workingArray = unique(connectionHistory, ["date", "user"]);

    //inWaiting.map((x) => dates.push(x.date));
    const var1 = [];
    const var2 = [];

    // Compte le nombre d'occurence par clé date
    const dataCounter = findOcc(workingArray, "date");

    // Rempli les tableaus de date et de nombre de connection.
    dataCounter.map((x) => (var1.push(x.date), var2.push(x.occurrence)));
    setDatesUniq(var1);
    setTotalNumberUniq(var2);
  };

  // Chart des connexions ;

  const data = {
    labels: dates,
    datasets: [
      {
        label: "Nb total de connection",
        data: totalNumber,
        fill: false,
        backgroundColor: "rgb(255, 99, 132)",
        borderColor: "rgba(255, 99, 132, 0.2)",
      },
      {
        label: "Nb de connection par utilisateur",
        data: totalNumberUniq,
        fill: false,
        backgroundColor: "rgb(54, 162, 235)",
        borderColor: "rgba(54, 162, 235, 0.2)",
      },
    ],
  };

  const options = {
    scales: {
      y: {
        beginAtZero: true,
      },
    },
  };

  return (
    <>
      <div className="main-container">
        <div className="dashboard-content">
          <h1> Statistiques</h1>
          <div className="main-space">
            <div id="sss">
              <h2>Nombre de connexion</h2>
              <Line data={data} options={options} />

              <Select
                name="interval"
                placeholder="Intervalle de temps"
                label="Intervalle"
                onChange={handleChange}
                value={interval}
                onClick={() => fetch(interval)}
              >
                <option value="d2">2 jours</option>
                <option value="d3">3 jours</option>
                <option value="d4">4 jours</option>
                <option value="w1">1 semaine</option>
                <option value="w2">2 semaines</option>
                <option value="w3">3 semaines</option>
                <option value="m1">1 mois</option>
                <option value="m2">2 mois</option>
                <option value="m3">3 mois</option>
                <option value="m6">6 mois</option>
              </Select>
            </div>
          </div>
        </div>
      </div>
    </>
  );
};

export default StatisticsPage;
