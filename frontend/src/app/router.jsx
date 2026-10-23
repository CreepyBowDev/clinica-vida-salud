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
import SpecialtiesPage from '../features/especialidades/pages/SpecialtiesPage';
import DoctorsPage from '../features/medicos/pages/DoctorsPage';
import DoctorEditorPage from '../features/medicos/pages/DoctorEditorPage';
import DoctorDetailPage from '../features/medicos/pages/DoctorDetailPage';
import DoctorSpecialtiesPage from '../features/medicos/pages/DoctorSpecialtiesPage';
import SpecialtyEditorPage from '../features/especialidades/pages/SpecialtyEditorPage';
import SpecialtyDetailPage from '../features/especialidades/pages/SpecialtyDetailPage';
import BitacoraPage from '../features/bitacora/pages/BitacoraPage';
import BitacoraDetailPage from '../features/bitacora/pages/BitacoraDetailPage';
import BitacoraWorkspace from '../features/bitacora/components/BitacoraWorkspace';
import ConsultoriosPage from '../features/consultorios/pages/ConsultoriosPage';
import ConsultorioEditorPage from '../features/consultorios/pages/ConsultorioEditorPage';
import ConsultorioDetailPage from '../features/consultorios/pages/ConsultorioDetailPage';
import AsignacionConsultorioPage from '../features/consultorios/pages/AsignacionConsultorioPage';
import HorariosPage from '../features/horarios/pages/HorariosPage';
import HorariosMedicoPage from '../features/horarios/pages/HorariosMedicoPage';
import ConfiguracionCitasPage from '../features/horarios/pages/ConfiguracionCitasPage';
import ReservarCitaPage from '../features/citas/pages/ReservarCitaPage';
import CitaDetallePage from '../features/agenda/pages/CitaDetallePage';
import AgendaPage from '../features/agenda/pages/AgendaPage';
import AgendaWorkspace from '../features/agenda/components/AgendaWorkspace';
import HistorialCitasPage from '../features/agenda/pages/HistorialCitasPage';
import MisCitasPage from '../features/agenda/pages/MisCitasPage';
import SeleccionarPacienteHistorialPage from '../features/agenda/pages/SeleccionarPacienteHistorialPage';

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
      { element: <AgendaWorkspace />, children: [
        { element: <RoleRoute roles={[ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA, ROLES.MEDICO]} />, children: [{ path: '/agenda', element: <AgendaPage /> }] },
        { element: <RoleRoute roles={Object.values(ROLES)} />, children: [{ path: '/citas/:id', element: <CitaDetallePage /> }] },
        { element: <RoleRoute roles={[ROLES.PACIENTE]} />, children: [
          { path: '/mis-citas', element: <MisCitasPage /> },
          { path: '/mis-citas/:id', element: <CitaDetallePage origin="mis-citas" /> },
        ] },
        { element: <RoleRoute roles={[ROLES.RECEPCIONISTA]} />, children: [
          { path: '/recepcion/pacientes/:id/citas', element: <HistorialCitasPage /> },
          { path: '/recepcion/historial-citas/:id', element: <CitaDetallePage origin="historial" /> },
        ] },
      ] },
      { element: <RoleRoute roles={[ROLES.PACIENTE]} />, children: [{ path: '/reservar-cita', element: <ReservarCitaPage /> }, { path: '/mi-perfil', element: <MyProfilePage /> }, { path: '/mi-perfil/correo', element: <ChangeEmailPage /> }] },
      { element: <RoleRoute roles={[ROLES.RECEPCIONISTA]} />, children: [
        { path: '/recepcion/historial-citas', element: <SeleccionarPacienteHistorialPage /> },
        { path: '/recepcion/citas/nueva', element: <ReservarCitaPage /> },
        { path: '/recepcion/activacion-web', element: <ActivationCodePage /> },
        { path: '/recepcion/pacientes', element: <PatientsPage /> },
        { path: '/recepcion/pacientes/nuevo', element: <NewPatientPage /> },
        { path: '/recepcion/pacientes/:id/editar', element: <EditPatientPage /> },
        { path: '/recepcion/pacientes/:id/activacion', element: <PatientActivationPage /> },
        { path: '/recepcion/pacientes/:id', element: <PatientDetailPage /> },
      ] },
      { element: <RoleRoute roles={[ROLES.ADMINISTRADOR]} />, children: [
        { path: '/administracion/consultorios', element: <ConsultoriosPage /> },
        { path: '/administracion/consultorios/nuevo', element: <ConsultorioEditorPage creating /> },
        { path: '/administracion/consultorios/:id/editar', element: <ConsultorioEditorPage /> },
        { path: '/administracion/consultorios/:id', element: <ConsultorioDetailPage /> },
        { path: '/administracion/medicos/:id/consultorio', element: <AsignacionConsultorioPage /> },
        { path: '/administracion/horarios', element: <HorariosPage /> },
        { path: '/administracion/horarios/medicos/:id', element: <HorariosMedicoPage /> },
        { path: '/administracion/horarios/configuracion', element: <ConfiguracionCitasPage /> },
        { element: <BitacoraWorkspace />, children: [
          { path: '/administracion/bitacora', element: <BitacoraPage /> },
          { path: '/administracion/bitacora/:id', element: <BitacoraDetailPage /> },
        ] },
        { path: '/administracion/especialidades', element: <SpecialtiesPage /> },
        { path: '/administracion/especialidades/nueva', element: <SpecialtyEditorPage creating /> },
        { path: '/administracion/especialidades/:id/editar', element: <SpecialtyEditorPage /> },
        { path: '/administracion/especialidades/:id', element: <SpecialtyDetailPage /> },
        { path: '/administracion/medicos', element: <DoctorsPage /> },
        { path: '/administracion/medicos/nuevo', element: <DoctorEditorPage creating /> },
        { path: '/administracion/medicos/:id/editar', element: <DoctorEditorPage /> },
        { path: '/administracion/medicos/:id/especialidades', element: <DoctorSpecialtiesPage /> },
        { path: '/administracion/medicos/:id', element: <DoctorDetailPage /> },
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
