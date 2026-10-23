import { matchPath } from 'react-router-dom';
import { AREAS, ROLES } from './roles';

const { ADMINISTRADOR: A, RECEPCIONISTA: R, MEDICO: M, PACIENTE: P } = ROLES;
const all = [A, R, M, P];
// Los CU son trazabilidad interna, nunca etiquetas de navegación ni permisos del servidor.
const page = (id, label, to, roles, cases, children = []) => ({ id, label, to, roles, cases, patterns: [to, ...children], kind: 'page' });
const pending = (id, label, roles, cases) => ({ id, label, roles, cases, kind: 'pending' });

export const NAVIGATION = [
  { id: 'usuarios', label: 'Usuarios y autenticación', icon: 'lock', items: [
    page('cuentas', 'Administrar cuentas', '/administracion/cuentas', [A], ['CU-03'], ['/administracion/cuentas/nueva', '/administracion/cuentas/:id']),
    page('contrasena', 'Cambiar contraseña', '/mi-cuenta/contrasena', all, ['CU-05']),
  ] },
  { id: 'pacientes', label: 'Pacientes y perfil personal', icon: 'user', items: [
    page('pacientes', 'Gestionar pacientes', '/recepcion/pacientes', [R], ['CU-07'], ['/recepcion/pacientes/nuevo', '/recepcion/pacientes/:id', '/recepcion/pacientes/:id/editar', '/recepcion/pacientes/:id/activacion']),
    page('activacion', 'Activación web', '/recepcion/activacion-web', [R], ['CU-07']),
    page('perfil', 'Mi perfil', '/mi-perfil', [P], ['CU-08'], ['/mi-perfil/correo']),
  ] },
  { id: 'medicos', label: 'Médicos y especialidades', icon: 'people', items: [
    page('especialidades', 'Gestionar especialidades', '/administracion/especialidades', [A], ['CU-09'], ['/administracion/especialidades/nueva', '/administracion/especialidades/:id', '/administracion/especialidades/:id/editar']),
    page('medicos', 'Gestionar médicos', '/administracion/medicos', [A], ['CU-10'], ['/administracion/medicos/nuevo', '/administracion/medicos/:id', '/administracion/medicos/:id/editar', '/administracion/medicos/:id/especialidades']),
  ] },
  { id: 'consultorios', label: 'Consultorios y horarios', icon: 'grid', items: [
    page('consultorios', 'Gestionar consultorios', '/administracion/consultorios', [A], ['CU-11'], ['/administracion/consultorios/nuevo', '/administracion/consultorios/:id', '/administracion/consultorios/:id/editar', '/administracion/medicos/:id/consultorio']),
    page('horarios', 'Configurar horarios', '/administracion/horarios', [A], ['CU-12'], ['/administracion/horarios/configuracion', '/administracion/horarios/medicos/:id']),
  ] },
  { id: 'reserva', label: 'Búsqueda y reserva de citas', icon: 'calendar', items: [
    page('programar', 'Programar cita', '/recepcion/citas/nueva', [R], ['CU-13', 'CU-14']),
    page('reservar', 'Reservar cita', '/reservar-cita', [P], ['CU-13', 'CU-14']),
  ] },
  { id: 'bitacora', label: 'Bitácora del Sistema', icon: 'info', items: [
    page('eventos', 'Bitácora del Sistema', '/administracion/bitacora', [A], ['CU-30'], ['/administracion/bitacora/:id']),
  ] },
  { id: 'reprogramacion', label: 'Reprogramación y cancelación', icon: 'calendar', items: [
    pending('reprogramar', 'Reprogramar cita', [R], ['CU-15']), pending('cancelar', 'Cancelar cita', [R], ['CU-16']),
  ] },
  { id: 'asistencia', label: 'Asistencia y estados', icon: 'check', items: [
    pending('llegada', 'Confirmar llegada', [R], ['CU-17']), pending('inasistencia', 'Registrar inasistencia', [R], ['CU-18']),
    pending('atendida', 'Marcar cita atendida', [M], ['CU-19']),
  ] },
  { id: 'agenda', label: 'Agenda e historial', icon: 'calendar', items: [
    page('agenda', 'Consultar agenda médica', '/agenda', [A, R, M], ['CU-20'], ['/citas/:id']),
    page('historial', 'Consultar historial de citas', '/recepcion/historial-citas', [R], ['CU-21'], ['/recepcion/pacientes/:id/citas', '/recepcion/historial-citas/:id']),
    page('mis-citas', 'Mis citas', '/mis-citas', [P], ['CU-21'], ['/mis-citas/:id']),
  ] },
  { id: 'historia', label: 'Historia clínica y consultas', icon: 'people', items: [
    pending('historia', 'Consultar historia clínica', [M, P], ['CU-22']), pending('consulta', 'Registrar consulta médica', [M], ['CU-23']),
  ] },
  { id: 'laboratorio', label: 'Laboratorio digital', icon: 'grid', items: [
    pending('solicitar', 'Solicitar estudios', [M], ['CU-24']), pending('incorporar', 'Incorporar resultados', [A], ['CU-25']),
    pending('revisar', 'Revisar y autorizar resultados', [M], ['CU-26']), pending('resultados', 'Consultar resultados publicados', [M, P], ['CU-27']),
  ] },
  { id: 'recordatorios', label: 'Recordatorios internos', icon: 'calendar', items: [pending('avisos', 'Consultar recordatorios', [P], ['CU-28'])] },
  { id: 'reportes', label: 'Reportes administrativos', icon: 'grid', items: [pending('reportes', 'Consultar reportes', [A], ['CU-29'])] },
];

// Son flujos públicos o acciones reales existentes, no páginas del sidebar protegido.
export const ACCESS_FLOWS = [
  { kind: 'public', cases: ['CU-01'], paths: ['/login'] },
  { kind: 'action', cases: ['CU-02'], component: 'LogoutButton' },
  { kind: 'public', cases: ['CU-04'], paths: ['/registro', '/vincular-ficha', '/verificar-registro'] },
  { kind: 'public', cases: ['CU-06'], paths: ['/olvide-mi-contrasena', '/recuperar-clave'] },
];

export function navigationForRole(role) {
  return NAVIGATION.map(module => ({ ...module, items: module.items.filter(item => item.roles.includes(role)) }))
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
