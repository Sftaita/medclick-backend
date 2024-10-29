import React, { useState, lazy, Suspense } from "react";
import { createRoot } from "react-dom/client";
import { HashRouter, Route, Routes, Navigate } from "react-router-dom";
import Navbar from "./components/Navbar";
import SpinnerLoader from "./components/SpinnerLoader/SpinnerLoader";
import PrivateRoute from "./components/PrivateRoute";
import AdminRoute from "./components/AdminRoute";
import authAPI from "./services/authAPI";
import "./Styles/general.css";
import AuthContext from "./contexts/AuthContext";

// Pages
import HomePage from "./pages/HomePage";
import LoginPage from "./pages/LoginPage";
const DashboardPage = lazy(() => import("./pages/DashboardPage/DashboardPage"));
const StatisticsPage = lazy(() => import("./pages/Statistics/StatisticsPage"));
const YearsPage = lazy(() => import("./pages/NonAdminPages/YearsPage"));
const SurgeriePage = lazy(() => import("./pages/NonAdminPages/SurgeriePage"));
const NomenclaturePage = lazy(() =>
  import("./pages/Nomenclature/NomenclaturePage")
);

// any CSS you import will output into a single css file (app.css in this case)
import "../css/app.css";

authAPI.setup();

const App = () => {
  const [isAuthenticated, setIsAuthenticated] = useState(
    authAPI.isAuthenticated()
  );
  const [userName, setUserName] = useState(authAPI.getIdentity());
  const [role, setRole] = useState(authAPI.getRole());

  return (
    <AuthContext.Provider
      value={{
        isAuthenticated,
        setIsAuthenticated,
        userName,
        setUserName,
        role,
        setRole,
      }}
    >
      <HashRouter>
        <Navbar />

        <main className="content">
          <Suspense fallback={<SpinnerLoader />}>
            <Routes>
              <Route path="/login" element={<LoginPage />} />
              <Route
                path="/dashboard"
                element={<AdminRoute element={<DashboardPage />} />}
              />
              <Route
                path="/statistics"
                element={<AdminRoute element={<StatisticsPage />} />}
              />
              <Route
                path="/nomenclatures"
                element={<AdminRoute element={<NomenclaturePage />} />}
              />
              <Route
                path="/years"
                element={<PrivateRoute element={<YearsPage />} />}
              />
              <Route
                path="/surgerie/:id"
                element={<PrivateRoute element={<SurgeriePage />} />}
              />
              <Route path="/" element={<HomePage />} />
              <Route path="*" element={<Navigate to="/" />} />
            </Routes>
          </Suspense>
        </main>
      </HashRouter>
    </AuthContext.Provider>
  );
};

const rootElement = document.querySelector("#app");
const root = createRoot(rootElement);
root.render(<App />);
