import { createBrowserRouter } from 'react-router-dom';
import { AppLayout } from './layouts/AppLayout';
import LoginPage from './pages/LoginPage';
import HomePage from './pages/HomePage';
import InterventionsPage from './pages/InterventionsPage';
import AddInterventionPage from './pages/AddInterventionPage';
import OperatingSessionPage from './pages/OperatingSessionPage';
import InterventionDetailPage from './pages/InterventionDetailPage';
import WeekPage from './pages/WeekPage';
import DayPage from './pages/DayPage';
import ProgressionPage from './pages/ProgressionPage';
import MilestonesPage from './pages/MilestonesPage';
import StatisticsPage from './pages/StatisticsPage';
import ProfilePage from './pages/ProfilePage';
import PartnersPage from './pages/PartnersPage';

/** Plan des routes — voir docs/PLAN.md pour la description de chaque écran. */
export const router = createBrowserRouter([
  { path: '/connexion', element: <LoginPage /> },
  {
    element: <AppLayout />, // écrans avec barre d'onglets
    children: [
      { path: '/', element: <HomePage /> },
      { path: '/interventions', element: <InterventionsPage /> },
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
      { path: '/interventions/ajouter', element: <AddInterventionPage /> },
      { path: '/interventions/journee', element: <OperatingSessionPage /> },
      { path: '/interventions/:id', element: <InterventionDetailPage /> },
      { path: '/jour/:date', element: <DayPage /> },
      { path: '/partenaires', element: <PartnersPage /> },
    ],
  },
]);
