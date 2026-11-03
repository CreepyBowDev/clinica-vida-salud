import { describe, expect, it } from 'vitest';
import { ACCESS_FLOWS, NAVIGATION, navigationAtPath, navigationForRole, navigationTitle } from './navigation';
import { AREAS, ROLES } from './roles';
import { crearRutas } from '../app/router';

describe('catálogo de navegación por responsabilidad', () => {
  it('cubre las rutas protegidas reales sin registrar destinos ficticios', () => {
    const paths = routes => routes.flatMap(route => [...(route.path ? [route.path] : []), ...paths(route.children ?? [])]);
    const actual = paths(crearRutas());
    const patterns = NAVIGATION.flatMap(module => module.items.flatMap(item => item.patterns ?? []));
    expect(patterns.every(pattern => actual.includes(pattern))).toBe(true);
    const roots = ['/inicio', ...Object.values(AREAS).map(area => area.path)];
    const protectedPaths = actual.filter(path => !roots.includes(path) && /^\/(administracion|recepcion|mi-perfil|mi-cuenta|reservar-cita|agenda|asistencia|citas|mis-citas|historia-clinica|registrar-consulta|laboratorio|recordatorios|reportes)(\/|$)/.test(path));
    expect([...patterns].sort()).toEqual(protectedPaths.sort());
  });
  it('conserva los treinta CU sin convertir acciones o flujos públicos en páginas protegidas', () => {
    const cases = [...NAVIGATION.flatMap(module => module.items.flatMap(item => item.cases)), ...ACCESS_FLOWS.flatMap(flow => flow.cases)];
    expect([...new Set(cases)].sort()).toEqual(Array.from({ length: 30 }, (_, i) => `CU-${String(i + 1).padStart(2, '0')}`));
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
    expect(navigationForRole(role).flatMap(module => module.items).filter(item => item.kind === 'page').map(item => item.id).sort()).toEqual([...ids].sort());
  });
  it.each([
    [ROLES.ADMINISTRADOR, '/reportes', 'reportes', 'reportes', 'Consultar reportes'],
    [ROLES.PACIENTE, '/recordatorios', 'recordatorios', 'avisos', 'Consultar recordatorios'],
    [ROLES.ADMINISTRADOR, '/recordatorios', 'recordatorios', 'avisos', 'Consultar recordatorios'],
    [ROLES.ADMINISTRADOR, '/laboratorio/solicitar', 'historia', 'solicitar', 'Solicitar estudios'],
    [ROLES.MEDICO, '/citas/41/consulta/solicitar-estudio', 'historia', 'solicitar', 'Solicitar estudio de laboratorio'],
    [ROLES.ADMINISTRADOR, '/laboratorio/pacientes/21', 'historia', 'resultados', 'Estudios del paciente'],
    [ROLES.MEDICO, '/laboratorio/consultas/44', 'historia', 'resultados', 'Estudios de la consulta'],
    [ROLES.PACIENTE, '/laboratorio/solicitudes/71', 'historia', 'resultados', 'Solicitud de laboratorio'],
    [ROLES.ADMINISTRADOR, '/historia-clinica/pacientes/42', 'historia', 'historia', 'Historia clínica'],
    [ROLES.MEDICO, '/citas/42/consulta', 'historia', 'historia', 'Consulta médica'],
    [ROLES.MEDICO, '/citas/42/consulta/versiones', 'historia', 'historia', 'Versiones de consulta'],
    [ROLES.ADMINISTRADOR, '/historia-clinica/pacientes/42/antecedentes/versiones', 'historia', 'historia', 'Versiones de antecedentes'],
    [ROLES.PACIENTE, '/historia-clinica', 'historia', 'historia', 'Consultar historia clínica'],
    [ROLES.ADMINISTRADOR, '/administracion/medicos/42/consultorio', 'organizacion', 'consultorios', 'Asignar consultorio'],
    [ROLES.ADMINISTRADOR, '/administracion/medicos/42/especialidades', 'organizacion', 'medicos', 'Especialidades del médico'],
    [ROLES.ADMINISTRADOR, '/administracion/horarios/configuracion', 'organizacion', 'horarios', 'Duración de citas'],
    [ROLES.ADMINISTRADOR, '/administracion/horarios/medicos/42', 'organizacion', 'horarios', 'Horarios del médico'],
    [ROLES.ADMINISTRADOR, '/administracion/cuentas/nueva', 'organizacion', 'cuentas', 'Crear cuenta del personal'],
    [ROLES.ADMINISTRADOR, '/administracion/bitacora/42', 'reportes', 'eventos', 'Detalle del evento'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes', 'pacientes', 'pacientes', 'Gestionar pacientes'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/nuevo', 'pacientes', 'pacientes', 'Registrar paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42', 'pacientes', 'pacientes', 'Detalle del paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42/editar', 'pacientes', 'pacientes', 'Editar paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42/activacion', 'pacientes', 'pacientes', 'Activación web del paciente'],
    [ROLES.ADMINISTRADOR, '/recepcion/activacion-web', 'pacientes', 'activacion', 'Activación web'],
    [ROLES.ADMINISTRADOR, '/recepcion/citas/nueva', 'agenda', 'programar', 'Programar cita'],
    [ROLES.ADMINISTRADOR, '/recepcion/citas/gestion', 'agenda', 'gestion-citas', 'Gestionar citas'],
    [ROLES.RECEPCIONISTA, '/citas/42/gestion', 'agenda', 'gestion-citas', 'Gestionar cita'],
    [ROLES.ADMINISTRADOR, '/asistencia', 'agenda', 'asistencia-citas', 'Gestionar asistencia'],
    [ROLES.RECEPCIONISTA, '/citas/42/asistencia', 'agenda', 'asistencia-citas', 'Asistencia de la cita'],
    [ROLES.MEDICO, '/citas/42/asistencia', 'agenda', 'asistencia-citas', 'Asistencia de la cita'],
    [ROLES.ADMINISTRADOR, '/recepcion/historial-citas', 'agenda', 'historial', 'Consultar historial de citas'],
    [ROLES.ADMINISTRADOR, '/recepcion/pacientes/42/citas', 'agenda', 'historial', 'Historial de citas'],
    [ROLES.ADMINISTRADOR, '/recepcion/historial-citas/42', 'agenda', 'historial', 'Detalle de cita'],
    [ROLES.RECEPCIONISTA, '/recepcion/pacientes/42/activacion', 'pacientes', 'pacientes', 'Activación web del paciente'],
    [ROLES.RECEPCIONISTA, '/recepcion/citas/nueva', 'agenda', 'programar', 'Programar cita'],
    [ROLES.PACIENTE, '/mi-perfil/correo', 'usuarios', 'perfil', 'Cambiar correo de acceso'],
    [ROLES.PACIENTE, '/reservar-cita', 'agenda', 'reservar', 'Reservar cita'],
    [ROLES.MEDICO, '/agenda', 'agenda', 'agenda', 'Consultar agenda médica'],
    [ROLES.RECEPCIONISTA, '/recepcion/historial-citas', 'agenda', 'historial', 'Consultar historial de citas'],
    [ROLES.RECEPCIONISTA, '/recepcion/pacientes/42/citas', 'agenda', 'historial', 'Historial de citas'],
    [ROLES.RECEPCIONISTA, '/recepcion/historial-citas/42', 'agenda', 'historial', 'Detalle de cita'],
    [ROLES.PACIENTE, '/mis-citas', 'agenda', 'mis-citas', 'Mis citas'],
    [ROLES.PACIENTE, '/mis-citas/42', 'agenda', 'mis-citas', 'Detalle de cita'],
  ])('%s: %s reconoce módulo, función y contexto', (role, path, moduleId, itemId, title) => {
    const active = navigationAtPath(navigationForRole(role), path);
    expect(active.module.id).toBe(moduleId); expect(active.item.id).toBe(itemId);
    expect(navigationTitle(path, active, AREAS[role])).toBe(title);
  });
  it.each([
    [ROLES.ADMINISTRADOR, ['Agenda y citas', 'Pacientes', 'Atención clínica', 'Recordatorios', 'Organización', 'Reportes y bitácora', 'Mi cuenta']],
    [ROLES.RECEPCIONISTA, ['Agenda y citas', 'Pacientes', 'Mi cuenta']],
    [ROLES.MEDICO, ['Mi agenda', 'Atención clínica', 'Mi cuenta']],
    [ROLES.PACIENTE, ['Mis citas', 'Mi salud', 'Recordatorios', 'Mi cuenta']],
  ])('%s recibe grupos de tareas ordenados sin duplicar destinos', (role, labels) => {
    const modules = navigationForRole(role), paths = modules.flatMap(module => module.items.map(item => item.to));
    expect(modules.map(module => module.label)).toEqual(labels);
    expect(new Set(paths).size).toBe(paths.length);
    expect(modules.at(-1).id).toBe('usuarios');
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
  });
  it('representa 28 CU administrativos con acceso operativo, sin implicar cierre completo de cada CU', () => {
    const items = navigationForRole(ROLES.ADMINISTRADOR).flatMap(module => module.items);
    const actions = ACCESS_FLOWS.filter(flow => ['action', 'pending-action'].includes(flow.kind) && flow.roles?.includes(ROLES.ADMINISTRADOR));
    const cases = [...items.flatMap(item => item.cases), ...actions.flatMap(item => item.cases), 'CU-01', 'CU-02', 'CU-06'];
    expect([...new Set(cases)].sort()).toEqual(Array.from({ length: 30 }, (_, i) => `CU-${String(i + 1).padStart(2, '0')}`).filter(id => !['CU-04', 'CU-08'].includes(id)));
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
