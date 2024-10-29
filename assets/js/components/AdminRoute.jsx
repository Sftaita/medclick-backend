import React, { useContext } from "react";
import { Navigate } from "react-router-dom";
import AuthContext from "../contexts/AuthContext";

const AdminRoute = ({ element, ...rest }) => {
  const { isAuthenticated, role } = useContext(AuthContext);

  if (isAuthenticated && role === "ROLE_ADMIN") {
    return element;
  }

  return <Navigate to="/" />;
};

export default AdminRoute;
