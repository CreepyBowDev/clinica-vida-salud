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
    const protectedPaths = actual.filter(path => !roots.includes(path) && /^\/(administracion|recepcion|mi-perfil|mi-cuenta|reservar-cita)(\/|$)/.test(path));
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
    [ROLES.ADMINISTRADOR, '/administracion/medicos/42/consultorio', 'consultorios', 'consultorios', 'Asignar consultorio'],
    [ROLES.ADMINISTRADOR, '/administracion/medicos/42/especialidades', 'medicos', 'medicos', 'Especialidades del médico'],
    [ROLES.ADMINISTRADOR, '/administracion/horarios/configuracion', 'consultorios', 'horarios', 'Duración de citas'],
    [ROLES.ADMINISTRADOR, '/administracion/horarios/medicos/42', 'consultorios', 'horarios', 'Horarios del médico'],
    [ROLES.ADMINISTRADOR, '/administracion/cuentas/nueva', 'usuarios', 'cuentas', 'Crear cuenta del personal'],
    [ROLES.ADMINISTRADOR, '/administracion/bitacora/42', 'bitacora', 'eventos', 'Detalle del evento'],
    [ROLES.RECEPCIONISTA, '/recepcion/pacientes/42/activacion', 'pacientes', 'pacientes', 'Activación web del paciente'],
    [ROLES.RECEPCIONISTA, '/recepcion/citas/nueva', 'reserva', 'programar', 'Programar cita'],
    [ROLES.PACIENTE, '/mi-perfil/correo', 'pacientes', 'perfil', 'Cambiar correo de acceso'],
    [ROLES.PACIENTE, '/reservar-cita', 'reserva', 'reservar', 'Reservar cita'],
  ])('%s: %s reconoce módulo, función y contexto', (role, path, moduleId, itemId, title) => {
    const active = navigationAtPath(navigationForRole(role), path);
    expect(active.module.id).toBe(moduleId); expect(active.item.id).toBe(itemId);
    expect(navigationTitle(path, active, AREAS[role])).toBe(title);
  });
  it('no reconoce una ruta ajena al rol, pública o inexistente como función propia', () => {
    const modules = navigationForRole(ROLES.PACIENTE);
    for (const path of ['/administracion/cuentas', '/login', '/otro']) expect(navigationAtPath(modules, path)).toBeNull();
    expect(navigationAtPath(navigationForRole(ROLES.ADMINISTRADOR), '/administracion/medicos/42/inexistente')).toBeNull();
    expect(navigationTitle('/otro', null, AREAS[ROLES.PACIENTE])).toBe('Página no encontrada');
  });
  it('conserva CU-18 Recepción inasistencia y CU-19 Médico atendida', () => {
    const items = NAVIGATION.flatMap(module => module.items);
    expect(items.find(item => item.cases.includes('CU-18'))).toMatchObject({ id: 'inasistencia', roles: [ROLES.RECEPCIONISTA] });
    expect(items.find(item => item.cases.includes('CU-19'))).toMatchObject({ id: 'atendida', roles: [ROLES.MEDICO] });
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
