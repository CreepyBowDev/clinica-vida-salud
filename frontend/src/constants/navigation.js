import { matchPath } from 'react-router-dom';
import { AREAS, ROLES } from './roles';
import { citaOperation, CITA_OPERATIONS } from './citaOperations';
import { clinicalPresentation, LAB_OPERATIONS } from './clinicalPresentation';
import { paymentPresentation, PAYMENT_PATTERNS, PAYMENT_VIEWS } from './paymentPresentation';

const { ADMINISTRADOR: A, RECEPCIONISTA: R, MEDICO: M, PACIENTE: P } = ROLES;
const all = [A, R, M, P];
// Los CU son trazabilidad interna, nunca etiquetas de navegación ni permisos del servidor.
const page = (id, label, to, roles, cases, children = []) => ({ id, label, to, roles, cases, patterns: [to, ...children], kind: 'page' });
const operationPage = (id, path, cu, children) => ({ ...page(id, CITA_OPERATIONS[id].label, `${path}?operacion=${id}`, CITA_OPERATIONS[id].roles, [cu]),
  operation: id, patterns: [path, ...children] });

export const NAVIGATION = [
  { id: 'usuarios', package: 'P1', label: 'Usuarios y autenticación', icon: 'lock', items: [
    page('cuentas', 'Administrar cuentas de usuario y su acceso', '/administracion/cuentas', [A], ['CU-03'], ['/administracion/cuentas/nueva', '/administracion/cuentas/:id']),
    page('contrasena', 'Cambiar contraseña propia', '/mi-cuenta/contrasena', all, ['CU-05']),
  ] },
  { id: 'pacientes', package: 'P2', label: 'Pacientes y perfil personal', icon: 'user', items: [
    page('pacientes', 'Gestionar el registro administrativo de pacientes', '/recepcion/pacientes', [A, R], ['CU-07'], ['/recepcion/pacientes/nuevo', '/recepcion/pacientes/:id', '/recepcion/pacientes/:id/editar', '/recepcion/pacientes/:id/activacion']),
    { ...page('activacion', 'Activación web', '/recepcion/activacion-web', [A, R], []), auxiliary: true },
    page('perfil', 'Actualizar el perfil personal', '/mi-perfil', [P], ['CU-08'], ['/mi-perfil/correo']),
  ] },
  { id: 'medicos', package: 'P3', label: 'Médicos y especialidades', icon: 'grid', items: [
    page('especialidades', 'Gestionar especialidades médicas', '/administracion/especialidades', [A], ['CU-09'], ['/administracion/especialidades/nueva', '/administracion/especialidades/:id', '/administracion/especialidades/:id/editar']),
    page('medicos', 'Gestionar médicos y sus especialidades', '/administracion/medicos', [A], ['CU-10'], ['/administracion/medicos/nuevo', '/administracion/medicos/:id', '/administracion/medicos/:id/editar', '/administracion/medicos/:id/especialidades']),
  ] },
  { id: 'consultorios', package: 'P4', label: 'Consultorios, horarios y disponibilidad', icon: 'grid', items: [
    page('consultorios', 'Gestionar consultorios y su asignación fija', '/administracion/consultorios', [A], ['CU-11'], ['/administracion/consultorios/nuevo', '/administracion/consultorios/:id', '/administracion/consultorios/:id/editar', '/administracion/medicos/:id/consultorio']),
    page('horarios', 'Configurar horarios y duración de citas', '/administracion/horarios', [A], ['CU-12'], ['/administracion/horarios/configuracion', '/administracion/horarios/medicos/:id']),
  ] },
  { id: 'reserva', package: 'P5', label: 'Registro y reserva de citas médicas', icon: 'calendar', items: [
    page('disponibilidad', 'Buscar médicos y consultar disponibilidad', '/consultar-disponibilidad', [A, R, P], ['CU-13']),
    page('programar', 'Programar o reservar una cita presencial', '/recepcion/citas/nueva', [A, R], ['CU-14']),
    page('reservar', 'Programar o reservar una cita presencial', '/reservar-cita', [P], ['CU-14']),
  ] },
  { id: 'gestion-citas', package: 'P6', label: 'Reprogramación y cancelación', icon: 'calendar', items: [
    operationPage('reprogramar', '/recepcion/citas/gestion', 'CU-15', ['/citas/:id/gestion', '/recepcion/pacientes/:id/citas']),
    operationPage('cancelar', '/recepcion/citas/gestion', 'CU-16', ['/citas/:id/gestion', '/recepcion/pacientes/:id/citas']),
  ] },
  { id: 'asistencia', package: 'P7', label: 'Estados de citas y control de asistencia', icon: 'calendar', items: [
    operationPage('llegada', '/asistencia', 'CU-17', ['/citas/:id/asistencia']),
    operationPage('inasistencia', '/asistencia', 'CU-18', ['/citas/:id/asistencia']),
    operationPage('atendida', '/asistencia', 'CU-19', ['/citas/:id/asistencia']),
  ] },
  { id: 'agenda', package: 'P8', label: 'Agenda e historial de citas', icon: 'calendar', items: [
    page('agenda', 'Consultar la agenda médica', '/agenda', [A, R, M], ['CU-20'], ['/citas/:id']),
    page('historial', 'Consultar el historial y seguimiento de citas', '/recepcion/historial-citas', [A, R], ['CU-21'], ['/recepcion/pacientes/:id/citas', '/recepcion/historial-citas/:id']),
    page('mis-citas', 'Consultar el historial y seguimiento de citas', '/mis-citas', [P], ['CU-21'], ['/mis-citas/:id']),
  ] },
  { id: 'historia', package: 'P9', label: 'Historia clínica y consultas médicas', icon: 'people', items: [
    page('historia', 'Consultar la historia clínica autorizada', '/historia-clinica', [A, M, P], ['CU-22'], ['/historia-clinica/pacientes/:id', '/citas/:id/consulta', '/citas/:id/consulta/versiones', '/historia-clinica/pacientes/:id/antecedentes/versiones']),
    page('consulta', 'Registrar o actualizar una consulta médica', '/registrar-consulta', [A, M], ['CU-23'], ['/citas/:id/consulta/editar']),
  ] },
  { id: 'laboratorio', package: 'P10', label: 'Laboratorio digital', icon: 'people', items: [
    page('solicitar', 'Solicitar estudios de laboratorio', '/laboratorio/solicitar', [A, M], ['CU-24'], ['/citas/:id/consulta/solicitar-estudio']),
    ...['documentos', 'revision'].map(mode => ({ ...page(mode, LAB_OPERATIONS[mode].label, `/laboratorio?operacion=${mode}`, LAB_OPERATIONS[mode].roles, [LAB_OPERATIONS[mode].cu]), labMode: mode, patterns: ['/laboratorio', '/laboratorio/pacientes/:id', '/laboratorio/consultas/:id', '/laboratorio/solicitudes/:id'] })),
    { ...page('resultados', 'Consultar solicitudes y descargar resultados autorizados', '/laboratorio', [A, M, P], ['CU-27'], ['/laboratorio/pacientes/:id', '/laboratorio/consultas/:id', '/laboratorio/solicitudes/:id']), labMode: 'consulta' },
  ] },
  { id: 'recordatorios', package: 'P11', label: 'Recordatorios automáticos', icon: 'calendar', items: [
    page('avisos', 'Consultar recordatorios de próximas citas y recibir Web Push opcional', '/recordatorios', [P, A], ['CU-28']),
  ] },
  { id: 'reportes', package: 'P12', label: 'Consultas y reportes administrativos', icon: 'info', items: [
    page('reportes', 'Generar reportes administrativos', '/reportes', [A], ['CU-29']),
  ] },
  { id: 'bitacora', package: 'P13', label: 'Bitácora del sistema', icon: 'info', items: [
    page('eventos', 'Consultar bitácora del sistema', '/administracion/bitacora', [A], ['CU-30'], ['/administracion/bitacora/:id']),
  ] },
  { id: 'pagos', package: 'P14', label: 'Pagos', icon: 'info', items: [
    ...Object.entries(PAYMENT_VIEWS).map(([view, { label, cu }]) => ({ ...page(`pagos-${view}`, label, `/pagos?vista=${view}`, [P], [cu]), paymentView: view,
      patterns: ['/pagos', '/pagos/obligaciones/:id', ...(view === 'pagar' ? ['/pagos/obligaciones/:id/pagar', '/pagos/retorno/:proveedor/:intento?'] : [])] })),
    page('pagos', 'Consultar historial financiero de un Paciente', '/pagos', [A], ['CU-34'], ['/pagos/pacientes/:pacienteId', '/pagos/pacientes/:pacienteId/obligaciones/:id']),
    { ...page('demostracion-pagos', 'Demostración de pagos', '/pagos/demostracion', [P, A], []), auxiliary: true },
  ] },
];

// Flujos públicos, acciones existentes o pendientes explícitas; no páginas del sidebar protegido.
export const ACCESS_FLOWS = [
  { package: 'P1', kind: 'public', roles: all, cases: ['CU-01'], paths: ['/login'] },
  { package: 'P1', kind: 'action', roles: all, cases: ['CU-02'], component: 'LogoutButton' },
  { package: 'P1', kind: 'public', roles: [P], cases: ['CU-04'], paths: ['/registro', '/vincular-ficha', '/verificar-registro'] },
  { package: 'P1', kind: 'public', roles: all, cases: ['CU-06'], paths: ['/olvide-mi-contrasena', '/recuperar-clave'] },
  { package: 'P1', kind: 'public', roles: [A, R, M], cases: [], paths: ['/activar-personal'] },
  { package: 'P1', kind: 'account', roles: all, cases: ['CU-05'], paths: ['/mi-cuenta/contrasena', '/clave-obligatoria'] },
  { package: 'P2', kind: 'account', roles: [P], cases: ['CU-08'], paths: ['/mi-perfil', '/mi-perfil/correo'] },
  // Acciones compartidas del detalle, sin páginas duplicadas por rol.
  { package: 'P10', kind: 'action', cases: ['CU-25'], roles: [A], paths: ['/laboratorio/solicitudes/:id'], component: 'GestionDocumentoLaboratorio' },
  { package: 'P10', kind: 'action', cases: ['CU-26'], roles: [A, M], paths: ['/laboratorio/solicitudes/:id'], component: 'GestionRevisionResultado' },
  { package: 'P14', kind: 'action', cases: ['CU-32'], roles: [P], paths: ['/pagos/obligaciones/:id/pagar'] },
];

export function navigationForRole(role) {
  return NAVIGATION.map(module => ({ ...module, role, label: module.labels?.[role] ?? module.label, items: module.items.filter(item => item.roles.includes(role)).map(item =>
    item.id === 'avisos' && role === A ? { ...item, label: 'Consultar recordatorios de próximas citas' }
      : item.id === 'historia' && role === P ? { ...item, patterns: ['/historia-clinica', '/citas/:id/consulta'] }
      : item.labMode && role !== A ? { ...item, patterns: item.patterns.filter(p => p !== '/laboratorio/pacientes/:id') } : item) }))
    .filter(module => module.items.length);
}

export function navigationDestination(item, pathname, search = '') {
  if (item.id === 'pagos' && ['/pagos/pacientes/:pacienteId', '/pagos/pacientes/:pacienteId/obligaciones/:id'].some(pattern => matchPath(pattern, pathname))) {
    const entry = paymentPresentation(pathname, search, A);
    return `${pathname}${entry.valid ? entry.search : ''}`;
  }
  if (item.paymentView && matchPath('/pagos/obligaciones/:id/pagar', pathname)) return `${pathname.replace(/\/pagar\/?$/i, '')}?vista=${item.paymentView}`;
  if (item.paymentView && matchPath('/pagos/obligaciones/:id', pathname)) return `${pathname}?vista=${item.paymentView}`;
  if (item.labMode && ['/laboratorio/solicitudes/:id', '/laboratorio/pacientes/:id', '/laboratorio/consultas/:id'].some(pattern => matchPath(pattern, pathname))) return `${pathname}?operacion=${item.labMode}`;
  if (['historia', 'consulta', 'solicitar'].includes(item.id)) {
    const context = ['/citas/:id/consulta', '/citas/:id/consulta/editar', '/citas/:id/consulta/solicitar-estudio', '/citas/:id/consulta/versiones'].map(pattern => matchPath(pattern, pathname)).find(Boolean);
    if (context) {
      const ownProcess = item.id === 'consulta' && matchPath('/citas/:id/consulta/editar', pathname)
        || item.id === 'solicitar' && matchPath('/citas/:id/consulta/solicitar-estudio', pathname);
      return `${ownProcess ? pathname : `/citas/${context.params.id}/consulta`}?recorrido=${item.id === 'solicitar' ? 'solicitar-estudio' : item.id}`;
    }
  }
  // Cambiar de operación con una cita seleccionada no abandona ni desmonta su ciclo privado.
  // La vuelta explícita al selector sigue disponible en el detalle y respeta su blocker.
  const group = CITA_OPERATIONS[item.operation]?.group;
  const contextual = group === 'gestion' ? ['/citas/:id/gestion', '/recepcion/pacientes/:id/citas']
    : group === 'asistencia' ? ['/citas/:id/asistencia'] : [];
  return contextual.some(pattern => matchPath(pattern, pathname)) ? `${pathname}?operacion=${item.operation}` : item.to;
}

export function navigationAtPath(modules, pathname, search = '') {
  if (PAYMENT_PATTERNS.some(pattern => matchPath(pattern, pathname))) {
    const module = modules.find(m => m.id === 'pagos');
    if (!module) return null;
    const admin = module.items.some(i => i.id === 'pagos') && (!module.items.some(i => i.paymentView) || !['/pagos/obligaciones/:id', '/pagos/obligaciones/:id/pagar', '/pagos/retorno/:proveedor/:intento?'].some(p => matchPath(p, pathname)));
    const role = module.role ?? (admin ? A : P), entry = paymentPresentation(pathname, search, role);
    // No atribuir rutas de otro actor al paquete visible.
    if ((admin && ['/pagos/obligaciones/:id', '/pagos/obligaciones/:id/pagar', '/pagos/retorno/:proveedor/:intento?'].some(p => matchPath(p, pathname))) ||
      (!admin && ['/pagos/pacientes/:pacienteId', '/pagos/pacientes/:pacienteId/obligaciones/:id'].some(p => matchPath(p, pathname)))) return null;
    if (!entry.valid) return { module, item: { id: 'pagos-contexto', label: 'Vista de pagos no disponible', to: '/pagos', kind: 'context', presentation: true } };
    const fixed = ['/pagos/obligaciones/:id/pagar', '/pagos/retorno/:proveedor/:intento?'].some(p => matchPath(p, pathname));
    const neutral = !admin && !entry.value && matchPath('/pagos/obligaciones/:id', pathname);
    const item = module.items.find(i => i.id === (matchPath('/pagos/demostracion', pathname) ? 'demostracion-pagos' : admin ? 'pagos' : `pagos-${entry.value ?? (fixed ? 'pagar' : 'obligaciones')}`));
    return { module, item: neutral ? { id: 'pagos-contexto', label: 'Detalle de obligación', to: '/pagos', kind: 'context', presentation: true } : { ...item, presentation: Boolean(entry.value) } };
  }
  const clinical = ['/historia-clinica', '/historia-clinica/pacientes/:id', '/citas/:id/consulta', '/citas/:id/consulta/versiones', '/historia-clinica/pacientes/:id/antecedentes/versiones', '/registrar-consulta', '/citas/:id/consulta/editar', '/laboratorio', '/laboratorio/pacientes/:id', '/laboratorio/consultas/:id', '/laboratorio/solicitudes/:id', '/laboratorio/solicitar', '/citas/:id/consulta/solicitar-estudio'].some(pattern => matchPath(pattern, pathname));
  if (clinical) {
    const params = new URLSearchParams(search), lab = ['/laboratorio', '/laboratorio/pacientes/:id', '/laboratorio/consultas/:id', '/laboratorio/solicitudes/:id'].some(pattern => matchPath(pattern, pathname));
    const requested = lab ? params.get('operacion') : params.get('recorrido');
    const fixedId = ['/registrar-consulta', '/citas/:id/consulta/editar'].some(pattern => matchPath(pattern, pathname)) ? 'consulta'
      : ['/laboratorio/solicitar', '/citas/:id/consulta/solicitar-estudio'].some(pattern => matchPath(pattern, pathname)) ? 'solicitar'
        : ['/historia-clinica', '/historia-clinica/pacientes/:id', '/historia-clinica/pacientes/:id/antecedentes/versiones'].some(pattern => matchPath(pattern, pathname)) ? 'historia' : null;
    const id = lab ? requested === 'documentos' || requested === 'revision' ? requested : 'resultados'
      : fixedId ?? (requested === 'solicitar-estudio' ? 'solicitar' : requested === 'consulta' ? 'consulta' : 'historia');
    const module = modules.find(m => m.items.some(i => i.id === id));
    const fallback = modules.find(m => m.id === (lab ? 'laboratorio' : 'historia'));
    const fixedOperation = ['/registrar-consulta', '/citas/:id/consulta/editar', '/laboratorio/solicitar', '/citas/:id/consulta/solicitar-estudio'].some(pattern => matchPath(pattern, pathname));
    if (fixedOperation && !module) return null;
    if (!module && !fallback) return null;
    if (!modules.some(scope => scope.items.some(candidate => candidate.patterns.some(pattern => matchPath(pattern, pathname))))) return null;
    const item = module?.items.find(i => i.id === id);
    const entry = clinicalPresentation(pathname, search, (module ?? fallback).role ?? item?.roles[0]);
    const neutral = lab && matchPath('/laboratorio/solicitudes/:id', pathname) && !params.has('operacion');
    // No reconocer ámbitos que el catálogo filtrado excluye para el actor.
    if (item && !item.patterns.some(pattern => matchPath(pattern, pathname)) && !(['/citas/:id/consulta', '/citas/:id/consulta/versiones'].some(pattern => matchPath(pattern, pathname)) && ['consulta', 'solicitar'].includes(id))) return null;
    return { module: module ?? fallback, item: entry.valid && item && !neutral ? { ...item, presentation: Boolean(entry.value) } : { id: 'clinica-contexto', label: entry.valid && neutral ? 'Solicitud de laboratorio' : 'Operación no disponible', to: lab ? '/laboratorio' : '/historia-clinica', kind: 'context', presentation: true } };
  }
  const operationPaths = [
    ['gestion-citas', 'gestion', ['/recepcion/citas/gestion', '/citas/:id/gestion']],
    ['asistencia', 'asistencia', ['/asistencia', '/citas/:id/asistencia']],
  ];
  if (new URLSearchParams(search).has('operacion')) operationPaths[0][2].push('/recepcion/pacientes/:id/citas');
  for (const [moduleId, group, patterns] of operationPaths) {
    if (!patterns.some(pattern => matchPath(pattern, pathname))) continue;
    const module = modules.find(value => value.id === moduleId);
    if (!module) return null;
    // El catálogo ya está filtrado por actor; nunca recuperar un ítem excluido por rol.
    const requested = module.items.find(item => item.operation === new URLSearchParams(search).get('operacion'));
    const role = module.role ?? requested?.roles[0];
    const entry = citaOperation(search, group, role);
    const item = entry.valid && entry.operation && module.items.find(item => item.operation === new URLSearchParams(search).get('operacion'));
    return { module, item: item || { id: `${moduleId}-contexto`, label: entry.valid ? (group === 'gestion' ? 'Gestionar cita' : matchPath('/citas/:id/asistencia', pathname) ? 'Asistencia de la cita' : 'Gestionar asistencia') : 'Operación no disponible', to: group === 'gestion' ? '/recepcion/citas/gestion' : '/asistencia', kind: 'context' } };
  }
  // Patrones completos: asignación pertenece a consultorios, no al prefijo /medicos.
  for (const module of modules) {
    const item = module.items.find(item => !item.operation && item.patterns?.some(pattern => matchPath(pattern, pathname)));
    if (item) return { module, item };
  }
  // El detalle compartido directo no concede acceso a la agenda del Médico.
  const agenda = modules.find(module => module.id === 'agenda');
  if (agenda && matchPath('/citas/:id', pathname)) return { module: agenda,
    item: { id: 'detalle-cita', label: 'Detalle de cita', to: pathname, kind: 'context' } };
  return null;
}

const contextPages = [
  ['/pagos/obligaciones/:id', 'Detalle de obligación'],
  ['/pagos/obligaciones/:id/pagar', 'Pagar'],
  ['/pagos/pacientes/:pacienteId/obligaciones/:id', 'Detalle de obligación'],
  ['/pagos/pacientes/:pacienteId', 'Pagos del paciente seleccionado'],
  ['/pagos/retorno/:proveedor/:intento?', 'Retorno al portal'],
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
  if (active.item.presentation || ['gestion-citas', 'asistencia'].includes(active.module.id)) return active.item.label;
  return contextPages.find(([pattern]) => matchPath(pattern, pathname))?.[1] ?? active.item.label;
}
