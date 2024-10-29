import React from "react";
import "./ExcelButton.css";
import BorderAllIcon from "@mui/icons-material/BorderAll";

const ExcelButton = ({ name, value, label, onChange, id }) => {
  return (
    <div className="excel_button_container">
      <div className="btn btn-sm btn-info">
        <BorderAllIcon fontSize="small" />
        <input
          type="radio"
          name={name}
          value={value}
          id={id}
          onChange={onChange}
          className="radio_excel"
        />
        <label htmlFor={id}>{label}</label>
      </div>
    </div>
  );
};

export default ExcelButton;
