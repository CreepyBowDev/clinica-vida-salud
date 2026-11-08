import { describe, expect, it } from 'vitest';
import { ACCESS_FLOWS, NAVIGATION, navigationAtPath, navigationDestination, navigationForRole, navigationTitle } from './navigation';
import { AREAS, ROLES } from './roles';
import { crearRutas } from '../app/router';
import { CITA_OPERATIONS, citaOperation } from './citaOperations';
import { clinicalPresentation, LAB_OPERATIONS } from './clinicalPresentation';
import { paymentPresentation, PAYMENT_VIEWS } from './paymentPresentation';

describe('catálogo de navegación por responsabilidad', () => {
  it.each(['toString', 'constructor', '__proto__', 'hasOwnProperty'])('D7 rechaza propiedades heredadas como modo clínico: %s', value => {
    for (const role of Object.values(ROLES)) for (const [pathname, key] of [['/laboratorio', 'operacion'], ['/citas/42/consulta', 'recorrido']]) {
      const search = `?${key}=${value}`;
      expect(clinicalPresentation(pathname, search, role).valid).toBe(false);
      const active = navigationAtPath(navigationForRole(role), pathname, search);
      if (role === ROLES.RECEPCIONISTA) expect(active).toBeNull();
      else expect(active.item).toMatchObject({ kind: 'context', label: 'Operación no disponible' });
    }
  });
  it.each([
    '/citas/42/consulta/versiones', '/historia-clinica/pacientes/42',
    '/historia-clinica/pacientes/42/antecedentes/versiones', '/laboratorio/pacientes/42',
  ])('D7 una query incompatible no atribuye a Paciente el ámbito excluido %s', pathname => {
    for (const search of ['', '?recorrido=consulta', '?recorrido=solicitar-estudio', '?operacion=documentos', '?operacion=revision', '?recorrido=constructor']) {
      expect(navigationAtPath(navigationForRole(ROLES.PACIENTE), pathname, search)).toBeNull();
    }
  });
  it.each(Object.values(ROLES))('D7 cada entrada de %s coincide con su ruta, título y CU después de un montaje nuevo', role => {
    const modules = navigationForRole(role);
    for (const module of modules) for (const item of module.items) {
      const url = new URL(item.to, 'https://example.test');
      const fresh = navigationAtPath(navigationForRole(role), url.pathname, url.search);
      expect(fresh.module.id).toBe(module.id);
      expect(fresh.item).toMatchObject({ id: item.id, label: item.label, cases: item.cases });
      expect(navigationTitle(url.pathname, fresh, AREAS[role])).toBe(item.id === 'consulta' ? 'Seleccionar cita para registrar consulta' : item.label);
      expect(`${module.label} ${item.label}`).not.toMatch(/\b(?:CU-\d+|P\d+)\b/);
    }
  });
  it.each([
    [ROLES.ADMINISTRADOR, [3, 5, 7, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 34]],
    [ROLES.RECEPCIONISTA, [5, 7, 13, 14, 15, 16, 17, 18, 20, 21]],
    [ROLES.MEDICO, [5, 19, 20, 22, 23, 24, 26, 27]],
    [ROLES.PACIENTE, [5, 8, 13, 14, 21, 22, 27, 28, 31, 32, 33]],
  ])('D6 %s tiene exactamente una opción individual por CU autorizado, con auxiliares independientes', (role, numbers) => {
    const modules = navigationForRole(role), items = modules.flatMap(module => module.items);
    expect(modules.every(module => module.role === role && module.items.length > 0)).toBe(true);
    expect(items.filter(item => !item.auxiliary).map(item => item.cases)).toEqual(numbers.map(number => [`CU-${String(number).padStart(2, '0')}`]));
    expect(items.filter(item => item.auxiliary).every(item => item.cases.length === 0)).toBe(true);
    expect(items.filter(item => item.auxiliary).map(item => item.id)).toEqual(role === ROLES.ADMINISTRADOR ? ['activacion', 'demostracion-pagos'] : role === ROLES.RECEPCIONISTA ? ['activacion'] : role === ROLES.PACIENTE ? ['demostracion-pagos'] : []);
    expect(new Set(items.map(item => item.to)).size).toBe(items.length);
    for (const item of items) {
      const url = new URL(item.to, 'https://example.test');
      expect(navigationAtPath(modules, url.pathname, url.search)?.item.id).toBe(item.id);
    }
  });
  it.each([
    [ROLES.ADMINISTRADOR, 'consulta', '/citas/42/consulta/editar', '', '/citas/42/consulta/editar?recorrido=consulta'],
    [ROLES.MEDICO, 'solicitar', '/citas/42/consulta/solicitar-estudio', '', '/citas/42/consulta/solicitar-estudio?recorrido=solicitar-estudio'],
    [ROLES.MEDICO, 'consulta', '/citas/42/consulta/versiones', '?recorrido=consulta', '/citas/42/consulta?recorrido=consulta'],
    [ROLES.MEDICO, 'historia', '/citas/42/consulta/editar', '?recorrido=consulta', '/citas/42/consulta?recorrido=historia'],
    [ROLES.ADMINISTRADOR, 'pagos', '/pagos/pacientes/9/obligaciones/42', '?vista=historial', '/pagos/pacientes/9/obligaciones/42?vista=historial'],
    [ROLES.ADMINISTRADOR, 'pagos', '/pagos/pacientes/9', '?vista=historial&vista=historial', '/pagos/pacientes/9'],
    [ROLES.PACIENTE, 'pagos-historial', '/pagos/obligaciones/42/pagar', '', '/pagos/obligaciones/42?vista=historial'],
  ])('D6 destino contextual de %s/%s preserva recurso y proceso sin trasladar datos privados', (role, id, pathname, search, expected) => {
    const item = navigationForRole(role).flatMap(module => module.items).find(item => item.id === id);
    expect(navigationDestination(item, pathname, search)).toBe(expected);
  });
  it.each([
    [ROLES.PACIENTE, '/pagos/obligaciones/42', '?vista=pagar&vista=pagar'],
    [ROLES.ADMINISTRADOR, '/laboratorio/solicitudes/42', '?operacion=documentos&operacion=revision'],
    [ROLES.RECEPCIONISTA, '/asistencia', '?operacion=atendida'],
    [ROLES.MEDICO, '/citas/42/consulta', '?recorrido=consulta&recorrido=consulta'],
    [ROLES.PACIENTE, '/citas/42/consulta', '?recorrido=solicitar-estudio'],
  ])('D6 %s no selecciona CU para presentación inválida o incompatible: %s%s', (role, pathname, search) => {
    const active = navigationAtPath(navigationForRole(role), pathname, search);
    expect(active?.item.kind).toBe('context'); expect(active?.item.cases).toBeUndefined();
  });
  it.each(Object.entries(PAYMENT_VIEWS))('D5 %s identifica una opción y una vista propias', (view, config) => {
    const modules = navigationForRole(ROLES.PACIENTE);
    for (const pathname of ['/pagos', '/pagos/obligaciones/42']) {
      const active = navigationAtPath(modules, pathname, `?vista=${view}`);
      expect(active.item).toMatchObject({ id: `pagos-${view}`, label: config.label, cases: [config.cu] });
      expect(navigationTitle(pathname, active, AREAS[ROLES.PACIENTE])).toBe(config.label);
    }
    expect(paymentPresentation('/pagos', `?vista=${view}&vista=${view}`, ROLES.PACIENTE).valid).toBe(false);
  });
  it('D5 historial administrativo nunca se identifica como historial propio', () => {
    for (const pathname of ['/pagos', '/pagos/pacientes/9', '/pagos/pacientes/9/obligaciones/42']) {
      const active = navigationAtPath(navigationForRole(ROLES.ADMINISTRADOR), pathname, '?vista=historial');
      expect(active.item.cases).toEqual(['CU-34']);
      expect(navigationTitle(pathname, active, AREAS[ROLES.ADMINISTRADOR])).toBe('Consultar historial financiero de un Paciente');
    }
  });
  it.each(Object.entries(LAB_OPERATIONS).flatMap(([mode, config]) => Object.values(ROLES).map(role => [mode, config, role])))('D4 %s %o %s resuelve modo y rechaza duplicados', (mode, config, role) => {
    const active = navigationAtPath(navigationForRole(role), '/laboratorio/solicitudes/71', `?operacion=${mode}`);
    expect(active?.item.id === (mode === 'consulta' ? 'resultados' : mode)).toBe(config.roles.includes(role));
    if (config.roles.includes(role)) expect(navigationTitle('/laboratorio/solicitudes/71', active, AREAS[role])).toBe(config.label);
    expect(clinicalPresentation('/laboratorio', `?operacion=${mode}&operacion=${mode}`, role).valid).toBe(false);
    expect(clinicalPresentation('/citas/41/consulta', `?operacion=${mode}`, role).valid).toBe(false);
  });
  it.each(Object.entries(CITA_OPERATIONS).flatMap(([operation, config]) => Object.values(ROLES).map(role => [operation, config, role])))('D3 %s %o %s resuelve operación según actor y conserva historial sin modo', (operation, config, role) => {
    const groupPaths = config.group === 'gestion' ? ['/recepcion/citas/gestion', '/citas/42/gestion', '/recepcion/pacientes/31/citas'] : ['/asistencia', '/citas/42/asistencia'];
    const modules = navigationForRole(role);
    for (const path of groupPaths) {
      const active = navigationAtPath(modules, path, `?operacion=${operation}`);
      expect(active?.item.id === operation).toBe(config.roles.includes(role));
      if (config.roles.includes(role)) expect(navigationTitle(path, active, AREAS[role])).toBe(config.label);
    }
    expect(citaOperation(`?operacion=${operation}&operacion=${operation}`, config.group, role).valid).toBe(false);
    expect(citaOperation(`?operacion=${operation}`, config.group === 'gestion' ? 'asistencia' : 'gestion', role).valid).toBe(false);
    expect(citaOperation('', config.group, role)).toMatchObject({ valid: true, operation: null });
  });
  it('conserva los 14 nombres oficiales y la correspondencia completa por paquete en orden de CU', () => {
    expect(NAVIGATION.map(module => module.label)).toEqual([
      'Usuarios y autenticación', 'Pacientes y perfil personal', 'Médicos y especialidades',
      'Consultorios, horarios y disponibilidad', 'Registro y reserva de citas médicas',
      'Reprogramación y cancelación', 'Estados de citas y control de asistencia', 'Agenda e historial de citas',
      'Historia clínica y consultas médicas', 'Laboratorio digital', 'Recordatorios automáticos',
      'Consultas y reportes administrativos', 'Bitácora del sistema', 'Pagos',
    ]);
    const expected = [[1, 2, 3, 4, 5, 6], [7, 8], [9, 10], [11, 12], [13, 14], [15, 16], [17, 18, 19], [20, 21], [22, 23], [24, 25, 26, 27], [28], [29], [30], [31, 32, 33, 34]];
    NAVIGATION.forEach((module, index) => {
      expect(module.package).toBe(`P${index + 1}`);
      const cases = [...module.items.flatMap(item => item.cases), ...ACCESS_FLOWS.filter(flow => flow.package === module.package).flatMap(flow => flow.cases)];
      expect([...new Set(cases)].sort()).toEqual(expected[index].map(number => `CU-${String(number).padStart(2, '0')}`));
      const firstCases = module.items.filter(item => item.cases.length).map(item => item.cases[0]);
      expect(firstCases).toEqual([...firstCases].sort());
    });
  });
  it('traza públicos, cuenta y acciones en rutas reales sin añadir enlaces protegidos', () => {
    const paths = routes => routes.flatMap(route => [...(route.path ? [route.path] : []), ...paths(route.children ?? [])]);
    const actual = paths(crearRutas());
    for (const flow of ACCESS_FLOWS) {
      expect(NAVIGATION.some(module => module.package === flow.package)).toBe(true);
      expect(flow.roles.length).toBeGreaterThan(0);
      expect(flow.paths?.length || flow.component).toBeTruthy();
      for (const path of flow.paths ?? []) expect(actual).toContain(path);
    }
    expect(ACCESS_FLOWS.find(flow => flow.cases.includes('CU-04'))).toMatchObject({ package: 'P1', kind: 'public', roles: [ROLES.PACIENTE], paths: ['/registro', '/vincular-ficha', '/verificar-registro'] });
    expect(navigationForRole(ROLES.ADMINISTRADOR)[0].items[0]).toMatchObject({ id: 'cuentas', cases: ['CU-03'], to: '/administracion/cuentas' });
    expect(ACCESS_FLOWS.find(flow => flow.cases.includes('CU-32'))).toMatchObject({ package: 'P14', kind: 'action', roles: [ROLES.PACIENTE], paths: ['/pagos/obligaciones/:id/pagar'] });
  });
  it.each(Object.values(ROLES))('%s conserva selección profunda única y patrones compatibles con los guards reales', role => {
    const guardedPaths = (routes, roles = Object.values(ROLES)) => routes.flatMap(route => {
      const allowed = route.element?.props.roles ?? roles;
      return [...(route.path ? [{ path: route.path, roles: allowed }] : []), ...guardedPaths(route.children ?? [], allowed)];
    });
    const actual = guardedPaths(crearRutas());
    const modules = navigationForRole(role);
    const patterns = modules.flatMap(module => module.items.filter(item => !item.operation && !item.labMode && !item.paymentView).flatMap(item => item.patterns));
    expect(new Set(patterns).size).toBe(patterns.length);
    for (const module of modules) for (const item of module.items) for (const pattern of item.patterns) {
      expect(actual.find(route => route.path === pattern)?.roles).toContain(role);
      const path = pattern.replace(/:proveedor/g, 'stripe').replace(/:[\w]+\??/g, '42');
      const active = navigationAtPath(modules, path, item.paymentView ? `?vista=${item.paymentView}` : item.operation || item.labMode ? `?operacion=${item.operation ?? item.labMode}` : '');
      expect([active?.module.id, active?.item.id]).toEqual([module.id, item.id]);
    }
  });
  it('no atribuye pagos propios al Administrador ni consulta financiera ajena al Paciente', () => {
    const admin = navigationForRole(ROLES.ADMINISTRADOR), patient = navigationForRole(ROLES.PACIENTE);
    expect(admin.find(module => module.package === 'P14').items[0]).toMatchObject({ label: 'Consultar historial financiero de un Paciente', cases: ['CU-34'] });
    expect(patient.find(module => module.package === 'P14').items.flatMap(i => i.cases)).toEqual(['CU-31', 'CU-32', 'CU-33']);
    for (const path of ['/pagos/obligaciones/42', '/pagos/obligaciones/42/pagar', '/pagos/retorno/stripe/42']) expect(navigationAtPath(admin, path)).toBeNull();
    for (const path of ['/pagos/pacientes/42', '/pagos/pacientes/42/obligaciones/42']) expect(navigationAtPath(patient, path)).toBeNull();
  });
  it('cubre las rutas protegidas reales sin registrar destinos ficticios', () => {
    const paths = routes => routes.flatMap(route => [...(route.path ? [route.path] : []), ...paths(route.children ?? [])]);
    const actual = paths(crearRutas());
    const patterns = NAVIGATION.flatMap(module => module.items.flatMap(item => item.patterns ?? []));
    expect(patterns.every(pattern => actual.includes(pattern))).toBe(true);
    const roots = ['/inicio', ...Object.values(AREAS).map(area => area.path)];
    const protectedPaths = actual.filter(path => !roots.includes(path) && /^\/(administracion|recepcion|mi-perfil|mi-cuenta|reservar-cita|consultar-disponibilidad|agenda|asistencia|citas|mis-citas|historia-clinica|registrar-consulta|laboratorio|recordatorios|reportes|pagos)(\/|$)/.test(path));
    expect([...new Set(patterns)].sort()).toEqual(protectedPaths.sort());
  });
  it('conserva los 34 CU sin convertir acciones o flujos públicos en páginas protegidas', () => {
    const cases = [...NAVIGATION.flatMap(module => module.items.flatMap(item => item.cases)), ...ACCESS_FLOWS.flatMap(flow => flow.cases)];
    expect([...new Set(cases)].sort()).toEqual(Array.from({ length: 34 }, (_, i) => `CU-${String(i + 1).padStart(2, '0')}`));
    expect(ACCESS_FLOWS.find(flow => flow.cases.includes('CU-02')).kind).toBe('action');
    expect(NAVIGATION.flatMap(module => module.items).filter(item => item.kind === 'pending').every(item => !item.to && !item.patterns)).toBe(true);
    expect(NAVIGATION.every(module => !/CU-\d/.test(module.label) && module.items.every(item => !/CU-\d/.test(item.label)))).toBe(true);
  });
  it.each(Object.values(ROLES))('%s solo recibe funcionalidades de su rol', role => {
    const modules = navigationForRole(role);
    expect(modules.length).toBeGreaterThan(0);
    expect(modules.every(module => module.items.every(item => item.roles.includes(role)))).toBe(true);
    expect(modules.flatMap(module => module.items).filter(item => item.kind === 'page').map(item => item.to)).not.toContain('/login');
  });
  it.each([
    [ROLES.ADMINISTRADOR, ['cuentas', 'contrasena', 'pacientes', 'activacion', 'especialidades', 'medicos', 'consultorios', 'horarios', 'disponibilidad', 'programar', 'eventos', 'reprogramar', 'cancelar', 'llegada', 'inasistencia', 'atendida', 'agenda', 'historial', 'historia', 'consulta', 'resultados', 'solicitar', 'avisos', 'reportes']],
    [ROLES.RECEPCIONISTA, ['contrasena', 'pacientes', 'activacion', 'disponibilidad', 'programar', 'reprogramar', 'cancelar', 'llegada', 'inasistencia', 'agenda', 'historial']],
    [ROLES.MEDICO, ['contrasena', 'atendida', 'agenda', 'historia', 'consulta', 'resultados', 'solicitar']],
    [ROLES.PACIENTE, ['contrasena', 'perfil', 'disponibilidad', 'reservar', 'mis-citas', 'historia', 'resultados', 'avisos']],
  ])('%s tiene exactamente sus páginas operativas sin nuevos privilegios para otros roles', (role, ids) => {
    expect(navigationForRole(role).flatMap(module => module.items).filter(item => item.kind === 'page').map(item => item.id).sort()).toEqual([...ids, ...(role === ROLES.ADMINISTRADOR ? ['documentos', 'revision', 'pagos', 'demostracion-pagos'] : role === ROLES.MEDICO ? ['revision'] : role === ROLES.PACIENTE ? ['pagos-obligaciones', 'pagos-pagar', 'pagos-historial', 'demostracion-pagos'] : [])].sort());
  });
  it.each([
    [ROLES.PACIENTE, '/pagos/obligaciones/11111111-1111-4111-8111-111111111111', 'pagos', 'pagos-contexto', 'Detalle de obligación'],
    [ROLES.PACIENTE, '/pagos/obligaciones/11111111-1111-4111-8111-111111111111/pagar', 'pagos', 'pagos-pagar', 'Pagar'],
    [ROLES.ADMINISTRADOR, '/pagos/pacientes/9', 'pagos', 'pagos', 'Pagos del paciente seleccionado'],
    [ROLES.ADMINISTRADOR, '/reportes', 'reportes', 'reportes', 'Generar reportes administrativos'],
    [ROLES.PACIENTE, '/recordatorios', 'recordatorios', 'avisos', 'Consultar recordatorios de próximas citas y recibir Web Push opcional'],
    [ROLES.ADMINISTRADOR, '/recordatorios', 'recordatorios', 'avisos', 'Consultar recordatorios de próximas citas'],
    [ROLES.ADMINISTRADOR, '/laboratorio/solicitar', 'laboratorio', 'solicitar', 'Solicitar estudios de laboratorio'],
    [ROLES.MEDICO, '/citas/41/consulta/solicitar-estudio', 'laboratorio', 'solicitar', 'Solicitar estudio de laboratorio'],
    [ROLES.ADMINISTRADOR, '/laboratorio/pacientes/21', 'laboratorio', 'resultados', 'Estudios del paciente'],
    [ROLES.MEDICO, '/laboratorio/consultas/44', 'laboratorio', 'resultados', 'Estudios de la consulta'],
    [ROLES.PACIENTE, '/laboratorio/solicitudes/71', 'laboratorio', 'clinica-contexto', 'Solicitud de laboratorio'],
    [ROLES.ADMINISTRADOR, '/historia-clinica/pacientes/42', 'historia', 'historia', 'Historia clínica'],
    [ROLES.MEDICO, '/citas/42/consulta', 'historia', 'historia', 'Consulta médica'],
    [ROLES.MEDICO, '/citas/42/consulta/versiones', 'historia', 'historia', 'Versiones de consulta'],
    [ROLES.ADMINISTRADOR, '/historia-clinica/pacientes/42/antecedentes/versiones', 'historia', 'historia', 'Versiones de antecedentes'],
    [ROLES.PACIENTE, '/historia-clinica', 'historia', 'historia', 'Consultar la historia clínica autorizada'],
    [ROLES.ADMINISTRADOR, '/administracion/medicos/42/consultorio', 'consultorios', 'consultorios', 'Asignar consultorio'],
    [ROLES.ADMINISTRADOR, '/administracion/medicos/42/especialidades', 'medicos', 'medicos', 'Especialidades del médico'],
    [ROLES.ADMINISTRADOR, '/administracion/horarios/configuracion', 'consultorios', 'horarios', 'Duración de citas'],
    [ROLES.ADMINISTRADOR, '/administracion/horarios/medicos/42', 'consultorios', 'horarios', 'Horarios del médico'],
    [ROLES.ADMINISTRADOR, '/administracion/cuentas/nueva', 'usuarios', 'cuentas', 'Crear cuenta del personal'],
    [ROLES.ADMINISTRADOR, '/administracion/bitacora/42', 'bitacora', 'eventos', 'Detalle del evento'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes', 'pacientes', 'pacientes', 'Gestionar el registro administrativo de pacientes'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/nuevo', 'pacientes', 'pacientes', 'Registrar paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42', 'pacientes', 'pacientes', 'Detalle del paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42/editar', 'pacientes', 'pacientes', 'Editar paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42/activacion', 'pacientes', 'pacientes', 'Activación web del paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/activacion-web', 'pacientes', 'activacion', 'Activación web'],
    [ROLES.ADMINISTRADOR, '/consultar-disponibilidad', 'reserva', 'disponibilidad', 'Buscar médicos y consultar disponibilidad'],
    [ROLES.RECEPCIONISTA, '/consultar-disponibilidad', 'reserva', 'disponibilidad', 'Buscar médicos y consultar disponibilidad'],
    [ROLES.PACIENTE, '/consultar-disponibilidad', 'reserva', 'disponibilidad', 'Buscar médicos y consultar disponibilidad'],
    [ROLES.ADMINISTRADOR, '/recepcion/citas/nueva', 'reserva', 'programar', 'Programar o reservar una cita presencial'],
    [ROLES.ADMINISTRADOR, '/recepcion/citas/gestion', 'gestion-citas', 'gestion-citas-contexto', 'Gestionar cita'],
    [ROLES.RECEPCIONISTA, '/citas/42/gestion', 'gestion-citas', 'gestion-citas-contexto', 'Gestionar cita'],
    [ROLES.ADMINISTRADOR, '/asistencia', 'asistencia', 'asistencia-contexto', 'Gestionar asistencia'],
    [ROLES.RECEPCIONISTA, '/citas/42/asistencia', 'asistencia', 'asistencia-contexto', 'Asistencia de la cita'],
    [ROLES.MEDICO, '/citas/42/asistencia', 'asistencia', 'asistencia-contexto', 'Asistencia de la cita'],
    [ROLES.ADMINISTRADOR, '/recepcion/historial-citas', 'agenda', 'historial', 'Consultar el historial y seguimiento de citas'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42/citas', 'agenda', 'historial', 'Historial de citas'],
    [ROLES.ADMINISTRADOR, '/recepcion/historial-citas/42', 'agenda', 'historial', 'Detalle de cita'],
    [ROLES.RECEPCIONISTA, '/recepcion/pacientes/42/activacion', 'pacientes', 'pacientes', 'Activación web del paciente'],
    [ROLES.RECEPCIONISTA, '/recepcion/citas/nueva', 'reserva', 'programar', 'Programar o reservar una cita presencial'],
    [ROLES.PACIENTE, '/mi-perfil/correo', 'pacientes', 'perfil', 'Cambiar correo de acceso'],
    [ROLES.PACIENTE, '/reservar-cita', 'reserva', 'reservar', 'Programar o reservar una cita presencial'],
    [ROLES.MEDICO, '/agenda', 'agenda', 'agenda', 'Consultar la agenda médica'],
    [ROLES.RECEPCIONISTA, '/recepcion/historial-citas', 'agenda', 'historial', 'Consultar el historial y seguimiento de citas'],
    [ROLES.RECEPCIONISTA, '/recepcion/pacientes/42/citas', 'agenda', 'historial', 'Historial de citas'],
    [ROLES.RECEPCIONISTA, '/recepcion/historial-citas/42', 'agenda', 'historial', 'Detalle de cita'],
    [ROLES.PACIENTE, '/mis-citas', 'agenda', 'mis-citas', 'Consultar el historial y seguimiento de citas'],
    [ROLES.PACIENTE, '/mis-citas/42', 'agenda', 'mis-citas', 'Detalle de cita'],
  ])('%s: %s reconoce módulo, función y contexto', (role, path, moduleId, itemId, title) => {
    const active = navigationAtPath(navigationForRole(role), path);
    expect(active.module.id).toBe(moduleId); expect(active.item.id).toBe(itemId);
    expect(navigationTitle(path, active, AREAS[role])).toBe(title);
  });
  it.each([
    [ROLES.ADMINISTRADOR, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]],
    [ROLES.RECEPCIONISTA, [1, 2, 5, 6, 7, 8]],
    [ROLES.MEDICO, [1, 7, 8, 9, 10]],
    [ROLES.PACIENTE, [1, 2, 5, 8, 9, 10, 11, 14]],
  ])('%s recibe paquetes ordenados sin duplicar destinos', (role, packages) => {
    const modules = navigationForRole(role), paths = modules.flatMap(module => module.items.map(item => item.to));
    expect(modules.map(module => module.package)).toEqual(packages.map(number => `P${number}`));
    expect(new Set(paths).size).toBe(paths.length);
    expect(modules[0].id).toBe('usuarios');
  });
  it('no reconoce una ruta ajena al rol, pública o inexistente como función propia', () => {
    const modules = navigationForRole(ROLES.PACIENTE);
    for (const path of ['/administracion/cuentas', '/login', '/otro', '/laboratorio/pacientes/21']) expect(navigationAtPath(modules, path)).toBeNull();
    expect(navigationAtPath(navigationForRole(ROLES.ADMINISTRADOR), '/administracion/medicos/42/inexistente')).toBeNull();
    expect(navigationTitle('/otro', null, AREAS[ROLES.PACIENTE])).toBe('Página no encontrada');
    for (const path of ['/historia-clinica/pacientes/42', '/citas/42/consulta/versiones', '/historia-clinica/pacientes/42/antecedentes/versiones']) expect(navigationAtPath(modules, path)).toBeNull();
    expect(navigationAtPath(navigationForRole(ROLES.RECEPCIONISTA), '/historia-clinica')).toBeNull();
  });
  it('D3 separa las tres entradas con páginas compartidas y roles intactos', () => {
    const items = NAVIGATION.flatMap(module => module.items);
    expect(items.find(item => item.cases.includes('CU-18'))).toMatchObject({ id: 'inasistencia', to: '/asistencia?operacion=inasistencia', cases: ['CU-18'] });
    expect(items.find(item => item.cases.includes('CU-19')).id).toBe('atendida');
    expect(navigationForRole(ROLES.RECEPCIONISTA).find(module => module.package === 'P7').items.map(item => item.id)).toEqual(['llegada', 'inasistencia']);
    expect(navigationForRole(ROLES.MEDICO).find(module => module.package === 'P7').items.map(item => item.id)).toEqual(['atendida']);
  });
  it('representa 28 CU administrativos con acceso operativo, sin implicar cierre completo de cada CU', () => {
    const items = navigationForRole(ROLES.ADMINISTRADOR).flatMap(module => module.items);
    const actions = ACCESS_FLOWS.filter(flow => ['action', 'pending-action'].includes(flow.kind) && flow.roles?.includes(ROLES.ADMINISTRADOR));
    const cases = [...items.flatMap(item => item.cases), ...actions.flatMap(item => item.cases), 'CU-01', 'CU-02', 'CU-06'];
    expect([...new Set(cases)].sort()).toEqual([...Array.from({ length: 30 }, (_, i) => `CU-${String(i + 1).padStart(2, '0')}`).filter(id => !['CU-04', 'CU-08'].includes(id)), 'CU-34']);
    expect([...new Set([...items.filter(item => item.kind === 'pending'), ...actions.filter(a => a.kind === 'pending-action')].flatMap(item => item.cases))]).toHaveLength(0);
    expect(items.find(item => item.id === 'reprogramar').cases).toEqual(['CU-15']);
    expect(items.filter(item => item.kind === 'pending').every(item => !item.to && !item.patterns)).toBe(true);
  });
  it.each(Object.values(ROLES))('%s reconoce detalle directo sin duplicar enlaces', role => {
    const active = navigationAtPath(navigationForRole(role), '/citas/17');
    expect(active.module.id).toBe('agenda'); expect(active.item.kind).toBe(role === ROLES.PACIENTE ? 'context' : 'page');
    expect(navigationTitle('/citas/17', active, AREAS[role])).toBe('Detalle de cita');
    expect(NAVIGATION.find(module => module.id === 'agenda').items.find(item => item.id === 'historial').kind).toBe('page');
  });
  it.each([
    [ROLES.MEDICO, '/administracion/cuentas'],
    [ROLES.PACIENTE, '/recepcion/pacientes/42'],
    [ROLES.RECEPCIONISTA, '/administracion/medicos/42/consultorio'],
    [ROLES.ADMINISTRADOR, AREAS[ROLES.PACIENTE].path],
  ])('orienta %s en ruta ajena %s sin convertirla en función propia', (role, path) => {
    const active = navigationAtPath(navigationForRole(role), path);
    expect(active).toBeNull(); expect(navigationTitle(path, active, AREAS[role])).toBe('Acceso no permitido');
  });
});
