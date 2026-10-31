import { gestion, cita, detalle, sesion } from './gestionFixtures';

export { cita, detalle, sesion };
export function asistencia(rol = 'Administrador', patch = {}) {
  const med = rol === 'Médico', recep = rol === 'Recepcionista';
  return { ...gestion(), consultado_en: '2026-11-01T02:20:00.000000Z',
    acciones: { confirmar_llegada: !med, registrar_inasistencia: false, marcar_atendida: false },
    impedimentos: { confirmar_llegada: med ? ['ACCESO_DENEGADO'] : [],
      registrar_inasistencia: med ? ['ACCESO_DENEGADO'] : ['CITA_INASISTENCIA_ANTICIPADA'],
      marcar_atendida: recep ? ['ACCESO_DENEGADO'] : ['CITA_ATENCION_ANTICIPADA'] }, ...patch };
}
export function llegada(original = asistencia(), actor = '1') {
  const { acciones, impedimentos, zona_horaria, consultado_en, ...cita } = original;
  return { ...cita, estado: 'Confirmada', actualizada_en: '2026-11-01T02:20:00.000001Z',
    ultimo_cambio: { id_cambio_cita: '21', id_actor: actor, operacion: 'LLEGADA', realizado_en: '2026-11-01T02:20:00.000000Z' } };
}
export function confirmada(rol = 'Administrador') {
  const actual = asistencia(rol), result = llegada(actual);
  return { ...actual, ...result, acciones: { ...actual.acciones, confirmar_llegada: false },
    impedimentos: { ...actual.impedimentos, confirmar_llegada: rol === 'Médico' ? ['ACCESO_DENEGADO'] : ['CITA_TRANSICION_NO_PERMITIDA'],
      registrar_inasistencia: rol === 'Médico' ? ['ACCESO_DENEGADO'] : ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_INASISTENCIA_ANTICIPADA'] } };
}

export function ausenciaElegible(rol = 'Administrador', patch = {}) {
  const medico = rol === 'Médico';
  return asistencia(rol, { consultado_en: cita.fin_at,
    acciones: { confirmar_llegada: false, registrar_inasistencia: !medico, marcar_atendida: rol !== 'Recepcionista' },
    impedimentos: { confirmar_llegada: medico ? ['ACCESO_DENEGADO'] : ['CITA_LLEGADA_FUERA_DE_PLAZO'],
      registrar_inasistencia: medico ? ['ACCESO_DENEGADO'] : [], marcar_atendida: rol === 'Recepcionista' ? ['ACCESO_DENEGADO'] : [] }, ...patch });
}
export function inasistencia(original = ausenciaElegible(), actor = '1') {
  const { acciones, impedimentos, zona_horaria, consultado_en, ...cita } = original;
  return { ...cita, estado: 'Inasistencia', actualizada_en: '2026-11-01T02:45:00.123457Z',
    ultimo_cambio: { id_cambio_cita: '22', id_actor: actor, operacion: 'INASISTENCIA', realizado_en: '2026-11-01T02:45:00.123456Z' } };
}
export function ausente(rol = 'Administrador') {
  const actual = ausenciaElegible(rol);
  return { ...actual, ...inasistencia(actual), acciones: { confirmar_llegada: false, registrar_inasistencia: false, marcar_atendida: false },
    impedimentos: { confirmar_llegada: rol === 'Médico' ? ['ACCESO_DENEGADO'] : ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_LLEGADA_FUERA_DE_PLAZO'],
      registrar_inasistencia: rol === 'Médico' ? ['ACCESO_DENEGADO'] : ['CITA_TRANSICION_NO_PERMITIDA'],
      marcar_atendida: rol === 'Recepcionista' ? ['ACCESO_DENEGADO'] : ['CITA_TRANSICION_NO_PERMITIDA'] } };
}

export function atencionElegible(rol = 'Administrador', estado = 'Programada') {
  const actual = ausenciaElegible(rol);
  return { ...actual, estado, consultado_en: cita.inicio_at,
    acciones: { ...actual.acciones, registrar_inasistencia: false, marcar_atendida: rol !== 'Recepcionista' },
    impedimentos: { ...actual.impedimentos, registrar_inasistencia: rol === 'Médico' ? ['ACCESO_DENEGADO'] : estado === 'Confirmada' ? ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_INASISTENCIA_ANTICIPADA'] : ['CITA_INASISTENCIA_ANTICIPADA'] } };
}
export function atencion(original = atencionElegible(), actor = '1') {
  const { acciones, impedimentos, zona_horaria, consultado_en, ...cita } = original;
  return { ...cita, estado: 'Atendida', actualizada_en: '2026-11-01T02:45:00.123457Z',
    ultimo_cambio: { id_cambio_cita: '23', id_actor: actor, operacion: 'ATENCION', realizado_en: '2026-11-01T02:45:00.123456Z' } };
}
export function atendida(rol = 'Administrador') {
  return { ...ausente(rol), ...atencion(atencionElegible(rol)) };
}
