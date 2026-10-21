import { createBrowserRouter, Navigate } from 'react-router-dom';
import AuthLayout from '../layouts/AuthLayout';
import MainLayout from '../layouts/MainLayout';
import LoginPage from '../features/auth/pages/LoginPage';
import HomePage from '../features/auth/pages/HomePage';
import RestrictedAccessPage from '../features/auth/pages/RestrictedAccessPage';
import ProtectedRoute from '../routes/ProtectedRoute';
import GuestRoute from '../routes/GuestRoute';
import RoleRoute from '../routes/RoleRoute';
import { AREAS, ROLES } from '../constants/roles';
import ChangePasswordPage from '../features/auth/pages/ChangePasswordPage';
import ForgotPasswordPage from '../features/auth/pages/ForgotPasswordPage';
import ResetPasswordPage from '../features/auth/pages/ResetPasswordPage';
import SessionGate from '../features/auth/components/SessionGate';
import { capturarEnlaceRecuperacion } from '../features/auth/utils/recoveryLink';
import AcceptInvitationPage from '../features/auth/pages/AcceptInvitationPage';
import AccountsPage from '../features/usuarios/pages/AccountsPage';
import AccountDetailPage from '../features/usuarios/pages/AccountDetailPage';
import NewStaffPage from '../features/usuarios/pages/NewStaffPage';
import CredentialReceiptHost from '../features/usuarios/components/CredentialReceiptHost';
import RegisterPatientPage from '../features/auth/pages/RegisterPatientPage';
import VerifyRegistrationPage from '../features/auth/pages/VerifyRegistrationPage';
import ActivationCodePage from '../features/pacientes/pages/ActivationCodePage';
import PatientsPage from '../features/pacientes/pages/PatientsPage';
import PatientDetailPage from '../features/pacientes/pages/PatientDetailPage';
import NewPatientPage from '../features/pacientes/pages/NewPatientPage';
import EditPatientPage from '../features/pacientes/pages/EditPatientPage';
import PatientActivationPage from '../features/pacientes/pages/PatientActivationPage';
import MyProfilePage from '../features/pacientes/pages/MyProfilePage';
import ChangeEmailPage from '../features/pacientes/pages/ChangeEmailPage';

const recoveryLink = capturarEnlaceRecuperacion();
const invitationLink = capturarEnlaceRecuperacion('/activar-personal');
export function crearRutas(enlace = recoveryLink, invitacion = invitationLink) { return [{ element: <CredentialReceiptHost />, children: [
  {
    element: <AuthLayout />,
    children: [
      { element: <GuestRoute />, children: [{ path: '/login', element: <LoginPage /> }] },
      { path: '/olvide-mi-contrasena', element: <ForgotPasswordPage /> },
      { path: '/recuperar-clave', element: <SessionGate><ResetPasswordPage enlace={enlace} /></SessionGate> },
      { path: '/activar-personal', element: <SessionGate><AcceptInvitationPage enlace={invitacion} /></SessionGate> },
      { path: '/registro', element: <SessionGate><RegisterPatientPage /></SessionGate> },
      { path: '/vincular-ficha', element: <SessionGate><RegisterPatientPage linking /></SessionGate> },
      { path: '/verificar-registro', element: <SessionGate><VerifyRegistrationPage /></SessionGate> },
      { element: <ProtectedRoute restrictedOnly />, children: [{ path: '/clave-obligatoria', element: <RestrictedAccessPage /> }] },
    ],
  },
  {
    element: <ProtectedRoute />,
    children: [{ element: <MainLayout />, children: [
      { path: '/inicio', element: <HomePage /> },
      { path: '/mi-cuenta/contrasena', element: <ChangePasswordPage /> },
      { element: <RoleRoute roles={[ROLES.PACIENTE]} />, children: [{ path: '/mi-perfil', element: <MyProfilePage /> }, { path: '/mi-perfil/correo', element: <ChangeEmailPage /> }] },
      { element: <RoleRoute roles={[ROLES.RECEPCIONISTA]} />, children: [
        { path: '/recepcion/activacion-web', element: <ActivationCodePage /> },
        { path: '/recepcion/pacientes', element: <PatientsPage /> },
        { path: '/recepcion/pacientes/nuevo', element: <NewPatientPage /> },
        { path: '/recepcion/pacientes/:id/editar', element: <EditPatientPage /> },
        { path: '/recepcion/pacientes/:id/activacion', element: <PatientActivationPage /> },
        { path: '/recepcion/pacientes/:id', element: <PatientDetailPage /> },
      ] },
      { element: <RoleRoute roles={[ROLES.ADMINISTRADOR]} />, children: [
        { path: '/administracion/cuentas', element: <AccountsPage /> },
        { path: '/administracion/cuentas/nueva', element: <NewStaffPage /> },
        { path: '/administracion/cuentas/:id', element: <AccountDetailPage /> },
      ] },
      ...Object.entries(AREAS).map(([rol, area]) => ({ element: <RoleRoute roles={[rol]} />, children: [{ path: area.path, element: <HomePage area /> }] })),
      { path: '*', element: <section className="page-message"><h1>Página no encontrada</h1><p>Selecciona una opción de la navegación para continuar.</p></section> },
    ] }],
  },
  { path: '/', element: <Navigate to="/inicio" replace /> },
] }]; }

export const appRoutes = crearRutas();

export const router = createBrowserRouter(appRoutes);
