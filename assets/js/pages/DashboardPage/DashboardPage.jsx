import React, { useState, useEffect } from "react";
import "./Dashboard.css";
import userAPI from "../../services/userAPI";
import SpinnerLoader from "../../components/SpinnerLoader/SpinnerLoader";
import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import "dayjs/locale/fr";
import Grid from "@mui/material/Grid";
import Chip from "@mui/material/Chip";
import Stack from "@mui/material/Stack";
import FaceIcon from "@mui/icons-material/Face";
import IconButton from "@mui/material/IconButton";
import AddCircleOutlineIcon from "@mui/icons-material/AddCircleOutline";
import { Typography } from "@mui/material";

dayjs.extend(relativeTime);
dayjs.locale("fr");

const DashboardPage = (props) => {
  //-----------------------  Mise en place -------------------//

  const [loading, setLoading] = useState(true);

  // Recherche des utilisateurs :

  const [users, setUsers] = useState([]);

  const fetch = async () => {
    try {
      const data = await userAPI.getUsersList();
      setUsers(data);
      setLoading(false);
    } catch (error) {
      console.log(error.response);
    }
  };

  useEffect(() => {
    fetch();
  }, []);

  //Formataur de date
  const formatDate = (str) => dayjs.unix(str).format("DD/MM/YYYY");
  const formatRelativeTime = (date) => dayjs(date).fromNow();

  return (
    <>
      <div className="dashboard-content">
        <Grid
          container
          direction="row"
          justifyContent="space-between"
          alignItems="center"
          marginBottom={4}
        >
          <Grid>
            {" "}
            <Stack
              direction="row"
              justifyContent="center"
              alignItems="center"
              spacing={2}
            >
              <Typography variant="h4">Gestion des utilisateur</Typography>
              <Chip
                label={
                  (loading && (
                    <h2>
                      <span className="badge badge-success">Chargement..</span>
                    </h2>
                  )) ||
                  users.length
                }
                variant="outlined"
                icon={<FaceIcon />}
                color="primary"
              />
            </Stack>
          </Grid>
          <Grid>
            <IconButton>
              <AddCircleOutlineIcon color="primary" fontSize="large" />
            </IconButton>
          </Grid>
        </Grid>

        {(loading && <SpinnerLoader />) || (
          <table class="table table-hover">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Prénom</th>
                <th>Spécialité</th>
                <th>Date de validation</th>
                <th>Nombre de connexion</th>
                <th>Dernière connexion</th>
              </tr>
            </thead>

            <tbody>
              {users.map((user) => (
                <tr key={user.id}>
                  <td data-label="lastnamme">{user.lastname}</td>
                  <td data-label="firstname">{user.firstname}</td>
                  <td data-label="speciality">{user.speciality}</td>
                  <td data-label="dateOfValidation">
                    {user.validatedAt &&
                      (formatDate(user.validatedAt.timestamp) || null)}
                  </td>
                  <td data-label="NbOfConnection">{user.nbOfConnection}</td>
                  <td data-label="lastConnection">
                    {user.lastLoginDate &&
                      formatRelativeTime(user.lastLoginDate)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  );
};

export default DashboardPage;
