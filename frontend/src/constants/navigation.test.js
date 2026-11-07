import { describe, expect, it } from 'vitest';
import { ACCESS_FLOWS, NAVIGATION, navigationAtPath, navigationForRole, navigationTitle } from './navigation';
import { AREAS, ROLES } from './roles';
import { crearRutas } from '../app/router';

describe('catálogo de navegación por responsabilidad', () => {
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
    const patterns = modules.flatMap(module => module.items.flatMap(item => item.patterns));
    expect(new Set(patterns).size).toBe(patterns.length);
    for (const module of modules) for (const item of module.items) for (const pattern of item.patterns) {
      expect(actual.find(route => route.path === pattern)?.roles).toContain(role);
      const path = pattern.replace(/:proveedor/g, 'stripe').replace(/:[\w]+\??/g, '42');
      const active = navigationAtPath(modules, path);
      expect([active?.module.id, active?.item.id]).toEqual([module.id, item.id]);
    }
  });
  it('no atribuye pagos propios al Administrador ni consulta financiera ajena al Paciente', () => {
    const admin = navigationForRole(ROLES.ADMINISTRADOR), patient = navigationForRole(ROLES.PACIENTE);
    expect(admin.find(module => module.package === 'P14').items[0]).toMatchObject({ label: 'Consultar historial financiero de un Paciente', cases: ['CU-34'] });
    expect(patient.find(module => module.package === 'P14').items[0].cases).toEqual(['CU-31', 'CU-33']);
    for (const path of ['/pagos/obligaciones/42', '/pagos/obligaciones/42/pagar', '/pagos/retorno/stripe/42']) expect(navigationAtPath(admin, path)).toBeNull();
    for (const path of ['/pagos/pacientes/42', '/pagos/pacientes/42/obligaciones/42']) expect(navigationAtPath(patient, path)).toBeNull();
  });
  it('cubre las rutas protegidas reales sin registrar destinos ficticios', () => {
    const paths = routes => routes.flatMap(route => [...(route.path ? [route.path] : []), ...paths(route.children ?? [])]);
    const actual = paths(crearRutas());
    const patterns = NAVIGATION.flatMap(module => module.items.flatMap(item => item.patterns ?? []));
    expect(patterns.every(pattern => actual.includes(pattern))).toBe(true);
    const roots = ['/inicio', ...Object.values(AREAS).map(area => area.path)];
    const protectedPaths = actual.filter(path => !roots.includes(path) && /^\/(administracion|recepcion|mi-perfil|mi-cuenta|reservar-cita|agenda|asistencia|citas|mis-citas|historia-clinica|registrar-consulta|laboratorio|recordatorios|reportes|pagos)(\/|$)/.test(path));
    expect([...patterns].sort()).toEqual(protectedPaths.sort());
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
    [ROLES.ADMINISTRADOR, ['cuentas', 'contrasena', 'pacientes', 'activacion', 'especialidades', 'medicos', 'consultorios', 'horarios', 'programar', 'eventos', 'gestion-citas', 'asistencia-citas', 'agenda', 'historial', 'historia', 'consulta', 'resultados', 'solicitar', 'avisos', 'reportes']],
    [ROLES.RECEPCIONISTA, ['contrasena', 'pacientes', 'activacion', 'programar', 'gestion-citas', 'asistencia-citas', 'agenda', 'historial']],
    [ROLES.MEDICO, ['contrasena', 'asistencia-citas', 'agenda', 'historia', 'consulta', 'resultados', 'solicitar']],
    [ROLES.PACIENTE, ['contrasena', 'perfil', 'reservar', 'mis-citas', 'historia', 'resultados', 'avisos']],
  ])('%s tiene exactamente sus páginas operativas sin nuevos privilegios para otros roles', (role, ids) => {
    expect(navigationForRole(role).flatMap(module => module.items).filter(item => item.kind === 'page').map(item => item.id).sort()).toEqual([...ids, ...([ROLES.ADMINISTRADOR, ROLES.PACIENTE].includes(role) ? ['pagos', 'demostracion-pagos'] : [])].sort());
  });
  it.each([
    [ROLES.PACIENTE, '/pagos/obligaciones/11111111-1111-4111-8111-111111111111', 'pagos', 'pagos', 'Detalle de obligación'],
    [ROLES.PACIENTE, '/pagos/obligaciones/11111111-1111-4111-8111-111111111111/pagar', 'pagos', 'pagos', 'Pagar'],
    [ROLES.ADMINISTRADOR, '/pagos/pacientes/9', 'pagos', 'pagos', 'Pagos del paciente seleccionado'],
    [ROLES.ADMINISTRADOR, '/reportes', 'reportes', 'reportes', 'Generar reportes administrativos'],
    [ROLES.PACIENTE, '/recordatorios', 'recordatorios', 'avisos', 'Consultar recordatorios de próximas citas y recibir Web Push opcional'],
    [ROLES.ADMINISTRADOR, '/recordatorios', 'recordatorios', 'avisos', 'Consultar recordatorios de próximas citas'],
    [ROLES.ADMINISTRADOR, '/laboratorio/solicitar', 'laboratorio', 'solicitar', 'Solicitar estudios de laboratorio'],
    [ROLES.MEDICO, '/citas/41/consulta/solicitar-estudio', 'laboratorio', 'solicitar', 'Solicitar estudio de laboratorio'],
    [ROLES.ADMINISTRADOR, '/laboratorio/pacientes/21', 'laboratorio', 'resultados', 'Estudios del paciente'],
    [ROLES.MEDICO, '/laboratorio/consultas/44', 'laboratorio', 'resultados', 'Estudios de la consulta'],
    [ROLES.PACIENTE, '/laboratorio/solicitudes/71', 'laboratorio', 'resultados', 'Solicitud de laboratorio'],
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
    [ROLES.ADMINISTRADOR, '/recepcion/citas/nueva', 'reserva', 'programar', 'Buscar médicos, consultar disponibilidad y programar una cita presencial'],
    [ROLES.ADMINISTRADOR, '/recepcion/citas/gestion', 'gestion-citas', 'gestion-citas', 'Reprogramar una cita o cancelar una cita'],
    [ROLES.RECEPCIONISTA, '/citas/42/gestion', 'gestion-citas', 'gestion-citas', 'Gestionar cita'],
    [ROLES.ADMINISTRADOR, '/asistencia', 'asistencia', 'asistencia-citas', 'Gestionar asistencia'],
    [ROLES.RECEPCIONISTA, '/citas/42/asistencia', 'asistencia', 'asistencia-citas', 'Asistencia de la cita'],
    [ROLES.MEDICO, '/citas/42/asistencia', 'asistencia', 'asistencia-citas', 'Asistencia de la cita'],
    [ROLES.ADMINISTRADOR, '/recepcion/historial-citas', 'agenda', 'historial', 'Consultar el historial y seguimiento de citas'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42/citas', 'agenda', 'historial', 'Historial de citas'],
    [ROLES.ADMINISTRADOR, '/recepcion/historial-citas/42', 'agenda', 'historial', 'Detalle de cita'],
    [ROLES.RECEPCIONISTA, '/recepcion/pacientes/42/activacion', 'pacientes', 'pacientes', 'Activación web del paciente'],
    [ROLES.RECEPCIONISTA, '/recepcion/citas/nueva', 'reserva', 'programar', 'Buscar médicos, consultar disponibilidad y programar una cita presencial'],
    [ROLES.PACIENTE, '/mi-perfil/correo', 'pacientes', 'perfil', 'Cambiar correo de acceso'],
    [ROLES.PACIENTE, '/reservar-cita', 'reserva', 'reservar', 'Buscar médicos, consultar disponibilidad y reservar una cita presencial'],
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
  it('integra las tres operaciones en una página compartida sin páginas por rol', () => {
    const items = NAVIGATION.flatMap(module => module.items);
    expect(items.find(item => item.cases.includes('CU-18'))).toMatchObject({ id: 'asistencia-citas', kind: 'page', cases: ['CU-17', 'CU-18', 'CU-19'] });
    expect(items.find(item => item.cases.includes('CU-19')).id).toBe('asistencia-citas');
    expect(navigationForRole(ROLES.RECEPCIONISTA).flatMap(module => module.items).find(item => item.id === 'asistencia-citas').cases).toEqual(['CU-17', 'CU-18']);
    expect(navigationForRole(ROLES.MEDICO).flatMap(module => module.items).find(item => item.id === 'asistencia-citas')).toMatchObject({ label: 'Marcar una cita como Atendida', cases: ['CU-19'] });
  });
  it('representa 28 CU administrativos con acceso operativo, sin implicar cierre completo de cada CU', () => {
    const items = navigationForRole(ROLES.ADMINISTRADOR).flatMap(module => module.items);
    const actions = ACCESS_FLOWS.filter(flow => ['action', 'pending-action'].includes(flow.kind) && flow.roles?.includes(ROLES.ADMINISTRADOR));
    const cases = [...items.flatMap(item => item.cases), ...actions.flatMap(item => item.cases), 'CU-01', 'CU-02', 'CU-06'];
    expect([...new Set(cases)].sort()).toEqual([...Array.from({ length: 30 }, (_, i) => `CU-${String(i + 1).padStart(2, '0')}`).filter(id => !['CU-04', 'CU-08'].includes(id)), 'CU-34']);
    expect([...new Set([...items.filter(item => item.kind === 'pending'), ...actions.filter(a => a.kind === 'pending-action')].flatMap(item => item.cases))]).toHaveLength(0);
    expect(items.find(item => item.id === 'gestion-citas').cases).toEqual(['CU-15', 'CU-16']);
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
