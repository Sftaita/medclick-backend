import React, { useState, useContext } from "react";
import authAPI from "../services/authAPI";
import userAPI from "../services/userAPI";
import AuthContext from "../contexts/AuthContext";
import Field from "../components/Forms/Field";
import { Link } from "react-router-dom";
import SpinnerLoader from "../components/SpinnerLoader/SpinnerLoader";

const LoginPage = ({ history }) => {
  const { setIsAuthenticated } = useContext(AuthContext);
  const { setRole } = useContext(AuthContext);
  const { setUserName } = useContext(AuthContext);
  const [loading, setLoading] = useState(false);

  const [credentials, setCredentials] = useState({
    username: "",
    password: "",
  });

  const [error, setError] = useState("");

  //Gestion des champs
  const handleChange = ({ currentTarget }) => {
    const value = currentTarget.value;
    const name = currentTarget.name;

    setCredentials({ ...credentials, [name]: value });
  };

  //Gestion du submit
  const handleSubmit = async (event) => {
    event.preventDefault();

    try {
      setLoading(true);
      await authAPI.authenticate(credentials);

      const ask = await authAPI.getRole();
      setRole(ask);

      const id = await authAPI.getIdentity();
      setUserName(id);

      setError("");
      setIsAuthenticated(true);

      history.replace("/dashboard");
    } catch (error) {
      setError(
        "Les informations ne correspondent pas ou l'email n'a pas été validé"
      );
      setLoading(false);
    }
  };

  return (
    <>
      <div className="form-content">
        {loading && <SpinnerLoader />}

        {!loading && (
          <div>
            <h1>Connexion à l'application</h1>

            <form onSubmit={handleSubmit}>
              <Field
                label="Adresse email"
                name="username"
                value={credentials.username}
                onChange={handleChange}
                placeholder="Adresse email"
                error={error}
              ></Field>

              <Field
                name="password"
                label="Mot de passe"
                value={credentials.password}
                onChange={handleChange}
                type="password"
                error=""
              ></Field>

              <div className="form-group">
                <button type="submit" className="btn btn-success">
                  Connexion
                </button>
                <Link to={"/"} className="btn btn-link">
                  Retour
                </Link>
              </div>
            </form>
          </div>
        )}
      </div>
    </>
  );
};

export default LoginPage;
