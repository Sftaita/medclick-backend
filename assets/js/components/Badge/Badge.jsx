import React from "react";
import Avatar from "@mui/material/Avatar";
import "./Badge.css";

const Badge = ({ label }) => {
  return (
    <>
      <div className="badge-content">
        <Avatar className="badge">{label}</Avatar>
        <div className="nav-link dropdown-toggle"></div>
      </div>
    </>
  );
};

export default Badge;
