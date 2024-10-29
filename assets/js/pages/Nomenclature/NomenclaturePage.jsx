import React, { useState, useEffect, useRef } from "react";
import "./Nomenclature.css";

import adminAPI from "../../services/adminAPI";
import Grid from "@mui/material/Grid";
import Chip from "@mui/material/Chip";
import Stack from "@mui/material/Stack";
import MenuItem from "@mui/material/MenuItem";
import IconButton from "@mui/material/IconButton";
import AddCircleOutlineIcon from "@mui/icons-material/AddCircleOutline";
import { Typography } from "@mui/material";
import ListIcon from "@mui/icons-material/List";
import CircularProgress from "@mui/material/CircularProgress";
import FormControl from "@mui/material/FormControl";
import Select from "@mui/material/Select";
import InputLabel from "@mui/material/InputLabel";
import AddNomenclatureDialog from "../../components/Nomenclature/AddNomenclatureDialog";

const NomenclaturePage = () => {
  const specialities = [
    { value: "ortho", title: "Chirurgie orthopédique" },
    { value: "dig", title: "Chirurgie digestive" },
    { value: "general", title: "Chirurgie générale" },
    { value: "uro", title: "Chirurgie urologique" },
    { value: "vasc", title: "Chirurgie vasculaire" },
    { value: "thor", title: "Chirurgie thoracique" },
    { value: "plastic", title: "Chirurgie plastique" },
    { value: "neuro", title: "Neurochirurgie" },
    { value: "transp", title: "Transplantation" },
  ];

  const types = [
    { value: "", title: "" },
    { value: "trauma", title: "Traumatologie" },
    { value: "elective", title: "Elective" },
  ];

  const getTypeTitle = (value) => {
    const type = types.find((type) => type.value === value);
    return type ? type.title : "";
  };

  const subTypes = [
    { value: "", title: "" },
    { value: "shoulder", title: "Épaule" },
    { value: "humerus", title: "Humérus" },
    { value: "elbow", title: "Coude" },
    { value: "forearm", title: "Avant-bras" },
    { value: "wristhand", title: "Poignet-Main" },
    { value: "back", title: "Rachis" },
    { value: "pelvic", title: "Bassin" },
    { value: "hip", title: "Hanche" },
    { value: "proximalFemur", title: "Fémur proximal" },
    { value: "midFemur", title: "Diaphyse fémorale" },
    { value: "distalFemur", title: "Fémur distal" },
    { value: "knee", title: "Genou" },
    { value: "limb", title: "Jambe" },
    { value: "ankle", title: "Cheville" },
    { value: "foot", title: "Pied" },
  ];

  const getSubTypeTitle = (value) => {
    const subType = subTypes.find((subType) => subType.value === value);
    return subType ? subType.title : "";
  };

  const [loading, setLoading] = useState(false);
  const [updateLoading, setUpdateLoading] = useState(false);
  const [specility, setSpeciality] = useState("ortho");
  const [nomenclatureList, setNomenclatureList] = useState([]);

  // Speciality Select controler
  const handleChange = (event) => {
    setSpeciality(event.target.value);
    fetchNomenclature(event.target.value);
  };

  const fetchNomenclature = async (specility) => {
    setLoading(true);
    try {
      const data = await adminAPI.fetchNomenclature(specility);
      setNomenclatureList(data);
    } catch (error) {
      console.log(error.response);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchNomenclature(specility);
  }, []);

  // Mode edition
  const [editingId, setEditingId] = useState(null);
  const [editedRow, setEditedRow] = useState({
    id: "",
    speciality: "",
    codeAmbulant: "",
    codeHospitalisation: "",
    n: "",
    name: "",
    type: "",
    subType: "",
  });

  const handleEdit = (nomenclature) => {
    setEditingId(nomenclature.id);
    setEditedRow({
      id: nomenclature.id,
      speciality: nomenclature.speciality,
      codeAmbulant: nomenclature.codeAmbulant,
      codeHospitalisation: nomenclature.codeHospitalisation,
      n: nomenclature.n,
      name: nomenclature.name,
      type: nomenclature.type,
      subType: nomenclature.subType,
    });
  };

  const handleSave = async (editedRow) => {
    setUpdateLoading(true);
    try {
      const data = await adminAPI.updateNomenclature(editedRow);
      setNomenclatureList((prevList) =>
        prevList.map((item) => (item.id === editedRow.id ? editedRow : item))
      );
      setEditingId(null);
    } catch (error) {
      console.log(error.response);
    } finally {
      setUpdateLoading(false);
    }
  };

  // Gestion de l'édition
  const rowRefs = useRef([]);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (
        editingId !== null &&
        rowRefs.current[editingId] &&
        !rowRefs.current[editingId].contains(event.target)
      ) {
        setEditingId(null);
      }
    };

    document.addEventListener("mousedown", handleClickOutside);
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, [editingId]);

  // Ajouter une nomenclature
  const [open, setOpen] = useState(false);

  const handleOpen = () => {
    setOpen(true);
  };

  const handleClose = () => {
    setOpen(false);
  };

  return (
    <Grid container direction={"column"} padding={4}>
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
            <Typography fullWidth variant="h4">
              Gestion des nomenclatures
            </Typography>
            <FormControl size="small">
              <InputLabel id="demo-simple-select-label">Spécialité</InputLabel>
              <Select
                labelId="demo-simple-select-label"
                id="demo-simple-select"
                value={specility}
                label="Spécialité"
                onChange={handleChange}
              >
                {specialities.map((speciality) => (
                  <MenuItem key={speciality.value} value={speciality.value}>
                    {speciality.title}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            {loading ? (
              <CircularProgress />
            ) : (
              <Chip
                label={nomenclatureList.length}
                variant="outlined"
                icon={<ListIcon />}
                color="primary"
              />
            )}
          </Stack>
        </Grid>
        <Grid>
          <IconButton>
            <AddCircleOutlineIcon
              color="primary"
              fontSize="large"
              onClick={handleOpen}
            />
          </IconButton>
        </Grid>
      </Grid>

      {(loading && (
        <div className="spinner-border text-success" role="status">
          <span className="sr-only">Chargement...</span>
        </div>
      )) || (
        <table class="table table-hover">
          <thead>
            <tr>
              <th>ID</th>
              <th>Spécialité</th>
              <th>Code Ambulant</th>
              <th>Code Hospitalisation</th>
              <th>N</th>
              <th>Nom</th>
              <th style={{ width: "180px" }}>Type</th>

              <th style={{ width: "180px" }}>Sous-type </th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {nomenclatureList.map((nomenclature) => (
              <tr
                key={nomenclature.id}
                ref={(el) => (rowRefs.current[nomenclature.id] = el)}
              >
                <td>{nomenclature.id}</td>
                <td>
                  {editingId === nomenclature.id ? (
                    <div class="form-group">
                      <select
                        value={editedRow.speciality}
                        class="form-control"
                        onChange={(e) =>
                          setEditedRow({
                            ...editedRow,
                            speciality: e.target.value,
                          })
                        }
                      >
                        {specialities.map((speciality) => (
                          <option
                            key={speciality.value}
                            value={speciality.value}
                          >
                            {speciality.title}
                          </option>
                        ))}
                      </select>
                    </div>
                  ) : (
                    nomenclature.speciality
                  )}
                </td>
                <td>
                  {editingId === nomenclature.id ? (
                    <div class="form-group">
                      <input
                        type="text"
                        class="form-control"
                        value={editedRow.codeAmbulant}
                        onChange={(e) =>
                          setEditedRow({
                            ...editedRow,
                            codeAmbulant: e.target.value,
                          })
                        }
                      />
                    </div>
                  ) : (
                    nomenclature.codeAmbulant
                  )}
                </td>
                <td>
                  {editingId === nomenclature.id ? (
                    <div class="form-group">
                      {" "}
                      <input
                        type="text"
                        class="form-control"
                        value={editedRow.codeHospitalisation}
                        onChange={(e) =>
                          setEditedRow({
                            ...editedRow,
                            codeHospitalisation: e.target.value,
                          })
                        }
                      />
                    </div>
                  ) : (
                    nomenclature.codeHospitalisation
                  )}
                </td>
                <td>
                  {editingId === nomenclature.id ? (
                    <div class="form-group">
                      <input
                        type="text"
                        class="form-control"
                        value={editedRow.n}
                        onChange={(e) =>
                          setEditedRow({ ...editedRow, n: e.target.value })
                        }
                      />
                    </div>
                  ) : (
                    nomenclature.n
                  )}
                </td>
                <td>
                  {editingId === nomenclature.id ? (
                    <div class="form-group">
                      <input
                        type="text"
                        class="form-control"
                        value={editedRow.name}
                        onChange={(e) =>
                          setEditedRow({ ...editedRow, name: e.target.value })
                        }
                      />
                    </div>
                  ) : (
                    nomenclature.name
                  )}
                </td>
                <td>
                  {editingId === nomenclature.id ? (
                    <div class="form-group">
                      {" "}
                      <select
                        value={editedRow.type}
                        class="form-control"
                        onChange={(e) =>
                          setEditedRow({ ...editedRow, type: e.target.value })
                        }
                      >
                        {types.map((type) => (
                          <option key={type.value} value={type.value}>
                            {type.title}
                          </option>
                        ))}
                      </select>{" "}
                    </div>
                  ) : (
                    getTypeTitle(nomenclature.type)
                  )}
                </td>
                <td>
                  {editingId === nomenclature.id ? (
                    <div class="form-group">
                      {" "}
                      <select
                        value={nomenclature.subType}
                        class="form-control"
                        onChange={(e) =>
                          setEditedRow({
                            ...editedRow,
                            subType: e.target.value,
                          })
                        }
                      >
                        {subTypes.map((subType) => (
                          <option key={subType.value} value={subType.value}>
                            {subType.title}
                          </option>
                        ))}
                      </select>
                    </div>
                  ) : (
                    getSubTypeTitle(nomenclature.subType)
                  )}
                </td>
                <td>
                  <div className="d-flex">
                    {editingId === nomenclature.id ? (
                      <button
                        type="button"
                        style={{ width: "100px" }}
                        class="btn btn-primary mr-2"
                        onClick={() => handleSave(editedRow)}
                      >
                        {updateLoading ? (
                          <div
                            class="spinner-grow text-light spinner-grow-sm"
                            role="status"
                          >
                            <span class="sr-only">Loading...</span>
                          </div>
                        ) : (
                          "enregistrer"
                        )}
                      </button>
                    ) : (
                      <button
                        type="button"
                        style={{ width: "100px" }}
                        class="btn btn-outline-primary mr-2"
                        onClick={() => handleEdit(nomenclature)}
                      >
                        Modifier
                      </button>
                    )}
                    <button
                      type="button"
                      class="btn btn-outline-danger btn-sm"
                      style={{ width: "100px" }}
                      onClick={() => handleDelete(nomenclature.id)}
                    >
                      Supprimer
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <AddNomenclatureDialog open={open} onClose={handleClose} />
    </Grid>
  );
};

export default NomenclaturePage;
