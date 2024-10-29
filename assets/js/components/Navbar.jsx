import React, { useContext, useState, useEffect } from "react";
import authAPI from "../services/authAPI";
import { NavLink } from "react-router-dom";
import AuthContext from "../contexts/AuthContext";

import Badge from "./Badge/Badge";

const Navbar = ({ history }) => {
  const { isAuthenticated, setIsAuthenticated } = useContext(AuthContext);
  const { role, setRole } = useContext(AuthContext);
  const { userName } = useContext(AuthContext);

  const handleLogout = () => {
    authAPI.logout();
    setIsAuthenticated(false);
    setRole("none");
    history.push("/login");
  };

  return (
    <nav className="navbar navbar-expand-lg navbar-dark bg-dark">
      {!isAuthenticated && (
        <NavLink className="navbar-brand" to="/">
          Med Click
        </NavLink>
      )}
      {isAuthenticated && (
        <NavLink className="navbar-brand" to="/home">
          Med Click
        </NavLink>
      )}

      <button
        className="navbar-toggler"
        type="button"
        data-toggle="collapse"
        data-target="#navbarColor02"
        aria-controls="navbarColor02"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span className="navbar-toggler-icon"></span>
      </button>

      <div className="collapse navbar-collapse" id="navbarColor02">
        {isAuthenticated && (
          <ul className="navbar-nav mr-auto">
            {role === "ROLE_ADMIN" && (
              <>
                <li className="nav-item">
                  <NavLink className="nav-link" to="/dashboard">
                    Dashboard
                  </NavLink>
                </li>
                <li>
                  <NavLink className="nav-link" to="/statistics">
                    Statisiques
                  </NavLink>
                </li>
                <li>
                  <NavLink className="nav-link" to="/nomenclatures">
                    Nomenclature
                  </NavLink>
                </li>
              </>
            )}
          </ul>
        )}

        <ul className="navbar-bar ml-auto">
          {(!isAuthenticated && (
            <>
              <div className="navbar-nav mt-2">
                <NavLink className="btn btn-success" to="/login">
                  Se Connecter
                </NavLink>
              </div>
            </>
          )) || (
            <div className="navbar-nav">
              <li className="nav-item dropdown">
                <a
                  className="nav-link"
                  data-toggle="dropdown"
                  href="#"
                  role="button"
                  id="dropdownMenuLink"
                  aria-haspopup="true"
                  aria-expanded="false"
                >
                  <Badge
                    label={
                      userName.firstname.charAt(0).toUpperCase() +
                      userName.lastname.charAt(0).toUpperCase()
                    }
                  />
                </a>

                <div className="dropdown-menu dropdown-menu-right">
                  <div className="dropdown-divider"></div>
                  <button
                    onClick={handleLogout}
                    className="btn text-danger "
                    href="#"
                  >
                    Se déconnecter
                  </button>
                </div>
              </li>
            </div>
          )}
        </ul>
      </div>
    </nav>
  );
};

export default Navbar;
