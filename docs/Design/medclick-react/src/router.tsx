import { createBrowserRouter, Navigate } from 'react-router-dom';
import { AppLayout } from './layouts/AppLayout';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import ResetPasswordPage from './pages/ResetPasswordPage';
import TermsPage from './pages/TermsPage';
import HomePage from './pages/HomePage';
import AddChoicePage from './pages/AddChoicePage';
import InterventionsPage from './pages/InterventionsPage';
import ConsultationsListPage from './pages/ConsultationsListPage';
import GardesListPage from './pages/GardesListPage';
import FormationsListPage from './pages/FormationsListPage';
import AddInterventionPage from './pages/AddInterventionPage';
import OperatingSessionPage from './pages/OperatingSessionPage';
import NomenclaturePage from './pages/NomenclaturePage';
import InterventionDetailPage from './pages/InterventionDetailPage';
import ConsultationFormPage from './pages/ConsultationFormPage';
import GardeFormPage from './pages/GardeFormPage';
import FormationFormPage from './pages/FormationFormPage';
import WeekPage from './pages/WeekPage';
import DayPage from './pages/DayPage';
import ProgressionPage from './pages/ProgressionPage';
import MilestonesPage from './pages/MilestonesPage';
import StatisticsPage from './pages/StatisticsPage';
import ProfilePage from './pages/ProfilePage';
import YearsPage from './pages/YearsPage';
import YearFormPage from './pages/YearFormPage';
import SurgeonsPage from './pages/SurgeonsPage';
import SurgeonFormPage from './pages/SurgeonFormPage';
import FavoritesPage from './pages/FavoritesPage';
import PartnersPage from './pages/PartnersPage';
import AdminPartnerPage from './pages/AdminPartnerPage';
import InfoPage from './pages/InfoPage';

/** Plan des routes — voir docs/PLAN.md pour la description de chaque écran. */
export const router = createBrowserRouter([
  /* Compte (sans connexion) */
  { path: '/connexion', element: <LoginPage /> },
  { path: '/inscription', element: <RegisterPage /> },
  { path: '/mot-de-passe-oublie', element: <ForgotPasswordPage /> },
  { path: '/reinitialiser/:token', element: <ResetPasswordPage /> },
  { path: '/cgu', element: <TermsPage /> },

  /* Administration (à reprendre dans medclick-admin) */
  { path: '/admin/partenaire', element: <AdminPartnerPage /> },

  {
    element: <AppLayout />, // écrans avec barre d'onglets
    children: [
      { path: '/', element: <HomePage /> },
      { path: '/activites', element: <InterventionsPage /> },
      { path: '/activites/consultations', element: <ConsultationsListPage /> },
      { path: '/activites/gardes', element: <GardesListPage /> },
      { path: '/activites/formations', element: <FormationsListPage /> },
      { path: '/semaine', element: <WeekPage /> },
      { path: '/progression', element: <ProgressionPage /> },
      { path: '/progression/milestones', element: <MilestonesPage /> },
      { path: '/progression/statistiques', element: <StatisticsPage /> },
      { path: '/profil', element: <ProfilePage /> },
    ],
  },
  {
    element: <AppLayout tabs={false} />, // écrans de saisie / détail plein écran
    children: [
      { path: '/ajouter', element: <AddChoicePage /> },
      { path: '/interventions', element: <Navigate to="/activites" replace /> },
      { path: '/interventions/ajouter', element: <AddInterventionPage /> },
      { path: '/interventions/journee', element: <OperatingSessionPage /> },
      { path: '/interventions/:id', element: <InterventionDetailPage /> },
      { path: '/nomenclature', element: <NomenclaturePage /> },
      { path: '/consultations/ajouter', element: <ConsultationFormPage /> },
      { path: '/consultations/:id', element: <ConsultationFormPage /> },
      { path: '/gardes/ajouter', element: <GardeFormPage /> },
      { path: '/gardes/:id', element: <GardeFormPage /> },
      { path: '/formations/ajouter', element: <FormationFormPage /> },
      { path: '/formations/:id', element: <FormationFormPage /> },
      { path: '/jour/:date', element: <DayPage /> },
      { path: '/annees', element: <YearsPage /> },
      { path: '/annees/:id', element: <YearFormPage /> },
      { path: '/chirurgiens', element: <SurgeonsPage /> },
      { path: '/chirurgiens/:id', element: <SurgeonFormPage /> },
      { path: '/favoris', element: <FavoritesPage /> },
      { path: '/partenaires', element: <PartnersPage /> },
      { path: '/profil/informations', element: <InfoPage title="Mes informations" /> },
      { path: '/aide', element: <InfoPage title="Aide et contact" /> },
      { path: '/a-propos', element: <InfoPage title="À propos de MedClick" /> },
      { path: '/confidentialite', element: <InfoPage title="Politique de confidentialité" /> },
    ],
  },
]);
