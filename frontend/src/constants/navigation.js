import { matchPath } from 'react-router-dom';
import { AREAS, ROLES } from './roles';

const { ADMINISTRADOR: A, RECEPCIONISTA: R, MEDICO: M, PACIENTE: P } = ROLES;
const all = [A, R, M, P];
// Los CU son trazabilidad interna, nunca etiquetas de navegación ni permisos del servidor.
const page = (id, label, to, roles, cases, children = []) => ({ id, label, to, roles, cases, patterns: [to, ...children], kind: 'page' });

export const NAVIGATION = [
  { id: 'agenda', label: 'Agenda y citas', labels: { [M]: 'Mi agenda', [P]: 'Mis citas' }, icon: 'calendar', items: [
    page('agenda', 'Consultar agenda médica', '/agenda', [A, R, M], ['CU-20'], ['/citas/:id']),
    page('mis-citas', 'Mis citas', '/mis-citas', [P], ['CU-21'], ['/mis-citas/:id']),
    page('programar', 'Programar cita', '/recepcion/citas/nueva', [A, R], ['CU-13', 'CU-14']),
    page('reservar', 'Reservar cita', '/reservar-cita', [P], ['CU-13', 'CU-14']),
    page('gestion-citas', 'Gestionar citas', '/recepcion/citas/gestion', [A, R], ['CU-15', 'CU-16'], ['/citas/:id/gestion']),
    page('asistencia-citas', 'Gestionar asistencia', '/asistencia', [A, R, M], ['CU-17', 'CU-18', 'CU-19'], ['/citas/:id/asistencia']),
    page('historial', 'Consultar historial de citas', '/recepcion/historial-citas', [A, R], ['CU-21'], ['/recepcion/pacientes/:id/citas', '/recepcion/historial-citas/:id']),
  ] },
  { id: 'pacientes', label: 'Pacientes', icon: 'user', items: [
    page('pacientes', 'Gestionar pacientes', '/recepcion/pacientes', [A, R], ['CU-07'], ['/recepcion/pacientes/nuevo', '/recepcion/pacientes/:id', '/recepcion/pacientes/:id/editar', '/recepcion/pacientes/:id/activacion']),
    page('activacion', 'Activación web', '/recepcion/activacion-web', [A, R], ['CU-07']),
  ] },
  { id: 'historia', label: 'Atención clínica', labels: { [P]: 'Mi salud' }, icon: 'people', items: [
    page('historia', 'Consultar historia clínica', '/historia-clinica', [A, M, P], ['CU-22'], ['/historia-clinica/pacientes/:id', '/citas/:id/consulta', '/citas/:id/consulta/versiones', '/historia-clinica/pacientes/:id/antecedentes/versiones']),
    page('consulta', 'Registrar o corregir consulta', '/registrar-consulta', [A, M], ['CU-23'], ['/citas/:id/consulta/editar']),
    page('resultados', 'Estudios y resultados', '/laboratorio', [A, M, P], ['CU-27'], ['/laboratorio/pacientes/:id', '/laboratorio/consultas/:id', '/laboratorio/solicitudes/:id']),
    page('solicitar', 'Solicitar estudios', '/laboratorio/solicitar', [A, M], ['CU-24'], ['/citas/:id/consulta/solicitar-estudio']),
  ] },
  { id: 'recordatorios', label: 'Recordatorios', icon: 'calendar', items: [
    page('avisos', 'Consultar recordatorios', '/recordatorios', [P, A], ['CU-28']),
  ] },
  { id: 'organizacion', label: 'Organización', icon: 'grid', items: [
    page('cuentas', 'Administrar cuentas', '/administracion/cuentas', [A], ['CU-03'], ['/administracion/cuentas/nueva', '/administracion/cuentas/:id']),
    page('especialidades', 'Gestionar especialidades', '/administracion/especialidades', [A], ['CU-09'], ['/administracion/especialidades/nueva', '/administracion/especialidades/:id', '/administracion/especialidades/:id/editar']),
    page('medicos', 'Gestionar médicos', '/administracion/medicos', [A], ['CU-10'], ['/administracion/medicos/nuevo', '/administracion/medicos/:id', '/administracion/medicos/:id/editar', '/administracion/medicos/:id/especialidades']),
    page('consultorios', 'Gestionar consultorios', '/administracion/consultorios', [A], ['CU-11'], ['/administracion/consultorios/nuevo', '/administracion/consultorios/:id', '/administracion/consultorios/:id/editar', '/administracion/medicos/:id/consultorio']),
    page('horarios', 'Configurar horarios', '/administracion/horarios', [A], ['CU-12'], ['/administracion/horarios/configuracion', '/administracion/horarios/medicos/:id']),
  ] },
  { id: 'reportes', label: 'Reportes y bitácora', icon: 'info', items: [
    page('reportes', 'Consultar reportes', '/reportes', [A], ['CU-29']),
    page('eventos', 'Bitácora del Sistema', '/administracion/bitacora', [A], ['CU-30'], ['/administracion/bitacora/:id']),
  ] },
  { id: 'usuarios', label: 'Mi cuenta', icon: 'lock', items: [
    page('perfil', 'Mi perfil', '/mi-perfil', [P], ['CU-08'], ['/mi-perfil/correo']),
    page('contrasena', 'Cambiar contraseña', '/mi-cuenta/contrasena', all, ['CU-05']),
  ] },
];

// Flujos públicos, acciones existentes o pendientes explícitas; no páginas del sidebar protegido.
export const ACCESS_FLOWS = [
  { kind: 'public', cases: ['CU-01'], paths: ['/login'] },
  { kind: 'action', cases: ['CU-02'], component: 'LogoutButton' },
  { kind: 'public', cases: ['CU-04'], paths: ['/registro', '/vincular-ficha', '/verificar-registro'] },
  { kind: 'public', cases: ['CU-06'], paths: ['/olvide-mi-contrasena', '/recuperar-clave'] },
  // Acciones compartidas del detalle, sin páginas duplicadas por rol.
  { kind: 'action', cases: ['CU-25'], roles: [A], component: 'GestionDocumentoLaboratorio' },
  { kind: 'action', cases: ['CU-26'], roles: [A, M], component: 'GestionRevisionResultado' },
];

export function navigationForRole(role) {
  return NAVIGATION.map(module => ({ ...module, label: module.labels?.[role] ?? module.label, items: module.items.filter(item => item.roles.includes(role)).map(item =>
    item.id === 'historia' && role === P ? { ...item, patterns: ['/historia-clinica', '/citas/:id/consulta'] }
      : item.id === 'resultados' && role !== A ? { ...item, patterns: item.patterns.filter(p => p !== '/laboratorio/pacientes/:id') } : item) }))
    .filter(module => module.items.length);
}

export function navigationAtPath(modules, pathname) {
  // Patrones completos: asignación pertenece a consultorios, no al prefijo /medicos.
  for (const module of modules) {
    const item = module.items.find(item => item.patterns?.some(pattern => matchPath(pattern, pathname)));
    if (item) return { module, item };
  }
  // El detalle compartido directo no concede acceso a la agenda del Médico.
  const agenda = modules.find(module => module.id === 'agenda');
  if (agenda && matchPath('/citas/:id', pathname)) return { module: agenda,
    item: { id: 'detalle-cita', label: 'Detalle de cita', to: pathname, kind: 'context' } };
  return null;
}

const contextPages = [
  ['/citas/:id/consulta/solicitar-estudio', 'Solicitar estudio de laboratorio'],
  ['/laboratorio/pacientes/:id', 'Estudios del paciente'],
  ['/laboratorio/consultas/:id', 'Estudios de la consulta'],
  ['/laboratorio/solicitudes/:id', 'Solicitud de laboratorio'],
  ['/registrar-consulta', 'Seleccionar cita para registrar consulta'],
  ['/citas/:id/consulta/editar', 'Documentar consulta médica'],
  ['/citas/:id/consulta/versiones', 'Versiones de consulta'],
  ['/historia-clinica/pacientes/:id/antecedentes/versiones', 'Versiones de antecedentes'],
  ['/citas/:id/consulta', 'Consulta médica'],
  ['/historia-clinica/pacientes/:id', 'Historia clínica'],
  ['/asistencia', 'Gestionar asistencia'],
  ['/citas/:id/asistencia', 'Asistencia de la cita'],
  ['/citas/:id/gestion', 'Gestionar cita'],
  ['/citas/:id', 'Detalle de cita'],
  ['/mis-citas/:id', 'Detalle de cita'],
  ['/recepcion/historial-citas/:id', 'Detalle de cita'],
  ['/recepcion/pacientes/:id/citas', 'Historial de citas'],
  ['/administracion/medicos/:id/consultorio', 'Asignar consultorio'],
  ['/administracion/horarios/configuracion', 'Duración de citas'],
  ['/administracion/horarios/medicos/:id', 'Horarios del médico'],
  ['/administracion/medicos/:id/especialidades', 'Especialidades del médico'],
  ['/recepcion/pacientes/:id/activacion', 'Activación web del paciente'],
  ['/mi-perfil/correo', 'Cambiar correo de acceso'],
  ['/administracion/cuentas/nueva', 'Crear cuenta del personal'],
  ['/recepcion/pacientes/nuevo', 'Registrar paciente'],
  ['/administracion/medicos/nuevo', 'Registrar médico'],
  ['/administracion/especialidades/nueva', 'Crear especialidad'],
  ['/administracion/consultorios/nuevo', 'Crear consultorio'],
  ['/recepcion/pacientes/:id/editar', 'Editar paciente'],
  ['/administracion/medicos/:id/editar', 'Editar médico'],
  ['/administracion/especialidades/:id/editar', 'Editar especialidad'],
  ['/administracion/consultorios/:id/editar', 'Editar consultorio'],
  ['/administracion/cuentas/:id', 'Detalle de cuenta'],
  ['/recepcion/pacientes/:id', 'Detalle del paciente'],
  ['/administracion/medicos/:id', 'Detalle del médico'],
  ['/administracion/especialidades/:id', 'Detalle de especialidad'],
  ['/administracion/consultorios/:id', 'Detalle del consultorio'],
  ['/administracion/bitacora/:id', 'Detalle del evento'],
];

export function navigationTitle(pathname, active, area) {
  if (pathname === '/inicio') return 'Inicio';
  if (pathname === area.path) return area.label;
  if (!active) return navigationAtPath(NAVIGATION, pathname) || Object.values(AREAS).some(value => value.path === pathname)
    ? 'Acceso no permitido' : 'Página no encontrada';
  return contextPages.find(([pattern]) => matchPath(pattern, pathname))?.[1] ?? active.item.label;
}
