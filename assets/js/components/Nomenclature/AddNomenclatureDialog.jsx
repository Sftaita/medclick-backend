import React, { useState } from "react";
import {
  TextField,
  Button,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Grid,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  CircularProgress,
} from "@mui/material";
import adminAPI from "../../services/adminAPI";

const AddNomenclatureDialog = ({ open, onClose }) => {
  const [formData, setFormData] = useState({
    speciality: "",
    codeAmbulant: "",
    codeHospitalisation: "",
    n: "",
    name: "",
    type: "",
    subType: "",
  });
  const [loading, setLoading] = useState(false);

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

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData({
      ...formData,
      [name]: value,
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    let dataToSend = { ...formData };

    if (dataToSend.speciality !== "ortho") {
      dataToSend.type = "";
      dataToSend.subType = "";
    }

    try {
      await adminAPI.createNomenclature(dataToSend);
      // Fermez le dialogue après une soumission réussie
      onClose();
    } catch (error) {
      console.log(error.response);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Ajouter une nomenclature</DialogTitle>
      <form onSubmit={handleSubmit}>
        <DialogContent>
          <Grid container spacing={2}>
            <Grid item xs={12}>
              <FormControl fullWidth>
                <InputLabel id="speciality-label">Spécialité</InputLabel>
                <Select
                  labelId="speciality-label"
                  id="speciality"
                  name="speciality"
                  value={formData.speciality}
                  onChange={handleChange}
                  label="Spécialité"
                >
                  {specialities.map((speciality) => (
                    <MenuItem key={speciality.value} value={speciality.value}>
                      {speciality.title}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                id="codeAmbulant"
                name="codeAmbulant"
                label="Code Ambulant"
                type="number"
                value={formData.codeAmbulant}
                onChange={handleChange}
              />
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                id="codeHospitalisation"
                name="codeHospitalisation"
                label="Code Hospitalisation"
                type="number"
                value={formData.codeHospitalisation}
                onChange={handleChange}
              />
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                id="n"
                name="n"
                label="N"
                type="number"
                value={formData.n}
                onChange={handleChange}
              />
            </Grid>
            <Grid item xs={12}>
              <TextField
                fullWidth
                id="name"
                name="name"
                label="Nom"
                value={formData.name}
                onChange={handleChange}
              />
            </Grid>
            {formData.speciality === "ortho" && (
              <>
                <Grid item xs={12}>
                  <FormControl fullWidth>
                    <InputLabel id="type-label">Type</InputLabel>
                    <Select
                      labelId="type-label"
                      id="type"
                      name="type"
                      value={formData.type}
                      onChange={handleChange}
                      label="Type"
                    >
                      {types.map((type) => (
                        <MenuItem key={type.value} value={type.value}>
                          {type.title}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                </Grid>
                <Grid item xs={12}>
                  <FormControl fullWidth>
                    <InputLabel id="subType-label">Sous-type</InputLabel>
                    <Select
                      labelId="subType-label"
                      id="subType"
                      name="subType"
                      value={formData.subType}
                      onChange={handleChange}
                      label="Sous-type"
                    >
                      {subTypes.map((subType) => (
                        <MenuItem key={subType.value} value={subType.value}>
                          {subType.title}
                        </MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                </Grid>
              </>
            )}
          </Grid>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} color="secondary">
            Annuler
          </Button>
          <Button
            variant="contained"
            color="primary"
            type="submit"
            disabled={loading}
          >
            {loading ? <CircularProgress size={24} /> : "Ajouter"}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
};

export default AddNomenclatureDialog;
