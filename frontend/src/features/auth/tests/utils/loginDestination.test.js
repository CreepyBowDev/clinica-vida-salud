import { describe, expect, it } from 'vitest';
import { destinoLogin, destinoTrasLogin } from '../../utils/loginDestination';
import { navigationForRole } from '../../../../constants/navigation';
import { ROLES } from '../../../../constants/roles';

describe('retorno seguro de presentación tras login', () => {
  it.each(Object.values(ROLES))('%s recupera todas sus entradas con query sin conceder otro CU', rol => {
    for (const item of navigationForRole(rol).flatMap(module => module.items).filter(item => item.to.includes('?'))) {
      const url = new URL(item.to, 'https://example.test');
      const from = destinoLogin(url.pathname, url.search);
      expect(from).toBe(item.to);
      expect(destinoTrasLogin(from, rol)).toBe(item.to);
    }
  });
  it.each([
    ['Recepcionista', '/citas/42/gestion?operacion=cancelar'],
    ['Administrador', '/recepcion/pacientes/42/citas?operacion=reprogramar'],
    ['Médico', '/citas/42/asistencia?operacion=atendida'],
    ['Médico', '/citas/42/consulta?recorrido=consulta'],
    ['Administrador', '/citas/42/consulta/editar?recorrido=consulta'],
    ['Médico', '/citas/42/consulta/solicitar-estudio?recorrido=solicitar-estudio'],
    ['Administrador', '/laboratorio/solicitudes/42?operacion=documentos'],
    ['Médico', '/laboratorio/solicitudes/42?operacion=revision'],
    ['Paciente', '/laboratorio/solicitudes/42?operacion=consulta'],
    ['Paciente', '/pagos/obligaciones/11111111-1111-4111-8111-111111111111?vista=pagar'],
    ['Administrador', '/pagos/pacientes/42?vista=historial'],
  ])('%s conserva recurso y modo de %s', (rol, from) => {
    const url = new URL(from, 'https://example.test');
    expect(destinoTrasLogin(destinoLogin(url.pathname, url.search), rol)).toBe(from);
  });
  it.each(Object.values(ROLES))('%s conserva o rechaza disponibilidad conforme al rol existente', rol => {
    expect(destinoTrasLogin(destinoLogin('/consultar-disponibilidad'), rol)).toBe(rol === ROLES.MEDICO ? '/inicio' : '/consultar-disponibilidad');
  });
  it('no almacena datos privados ni copia state/hash del destino original', () => {
    expect(destinoLogin('/pagos', '?vista=historial&token=FICTICIO&propuesta=PRIVADA')).toBe('/pagos?vista=historial');
    expect(destinoLogin('/mi-cuenta/contrasena', '?password=FICTICIA&codigo=123456')).toBe('/mi-cuenta/contrasena');
    expect(destinoTrasLogin('/pagos?vista=historial&token=FICTICIO', 'Paciente')).toBe('/inicio');
  });
  it.each([
    ['/asistencia', '?operacion=inasistencia&operacion=inasistencia'],
    ['/asistencia', '?operacion=constructor'],
    ['/asistencia', '?vista=historial'],
    ['/pagos', '?vista='],
    ['/pagos', '?vista=pagar&recorrido=consulta'],
    ['/pagos/demostracion', '?vista=pagar'],
    ['/laboratorio', '?operacion=toString'],
    ['/citas/42/consulta/editar', '?recorrido=historia'],
    ['/mi-cuenta/contrasena', '?operacion=cancelar'],
  ])('rechaza query inválida/repetida/incompatible %s%s sin elegir otro modo', (pathname, search) => {
    expect(destinoLogin(pathname, search)).toBe('/inicio');
    for (const rol of Object.values(ROLES)) expect(destinoTrasLogin(`${pathname}${search}`, rol)).toBe('/inicio');
  });
  it.each([
    ['Recepcionista', '/asistencia?operacion=atendida'],
    ['Médico', '/asistencia?operacion=llegada'],
    ['Paciente', '/citas/42/gestion?operacion=cancelar'],
    ['Paciente', '/laboratorio?operacion=revision'],
    ['Administrador', '/pagos?vista=pagar'],
    ['Recepcionista', '/pagos?vista=historial'],
  ])('revalida %s al recuperar %s aunque otro rol pudiera abrirlo', (rol, from) => {
    expect(destinoTrasLogin(from, rol)).toBe('/inicio');
  });
  it.each([undefined, null, {}, '//ajeno.example.test', 'https://ajeno.example.test', '/\\ajeno.example.test', '/pagos?vista=historial#privado'])('rechaza destinos manipulados %s', from => {
    expect(destinoTrasLogin(from, 'Paciente')).toBe('/inicio');
  });
  it('mantiene destinos y neutralidad históricos sin query', () => {
    for (const path of ['/inicio', '/mi-cuenta/contrasena', '/asistencia', '/citas/42/gestion']) {
      expect(destinoLogin(path)).toBe(path);
      expect(destinoTrasLogin(path, 'Administrador')).toBe(path);
    }
  });
});
