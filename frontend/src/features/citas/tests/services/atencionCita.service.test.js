import { afterEach, beforeEach, expect, it } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { marcarAtendida, confirmarLlegada, registrarInasistencia } from '../../services/asistenciaCita.service';
import { atencionElegible, atencion, cita } from '../utils/asistenciaFixtures';

const adapter = api.defaults.adapter;
let calls, saved, responder;
const entrada = patch => ({ id: cita.id_cita, rol: 'Administrador', original: atencionElegible(), declaracion: true, idActor: '1', ...patch });
beforeEach(() => {
  calls = []; saved = atencion();
  responder = config => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {}, data: { data: saved } });
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Médico'].flatMap(rol => ['Programada', 'Confirmada'].map(estado => [rol, estado])))('%s marca %s sin exigir llegada/fin/consulta previa', async (rol, estado) => {
  const original = atencionElegible(rol, estado); saved = atencion(original, '9007199254740995');
  const signal = new AbortController().signal;
  const result = await marcarAtendida(entrada({ rol, original, idActor: '9007199254740995', presencia_verificada: true,
    ausencia_verificada: true, estado: 'Atendida', diagnostico: 'NO-SALIR', id_medico: '99' }), signal);
  expect(calls).toHaveLength(2);
  expect(calls[0]).toMatchObject({ method: 'get', url: csrfURL, signal, sessionBound: true });
  expect(calls[1]).toMatchObject({ method: 'patch', url: `/citas/${cita.id_cita}/atencion`, signal, sessionBound: true });
  expect(JSON.parse(calls[1].data)).toEqual({ version_programacion_original: original.version_programacion,
    actualizada_en_original: original.actualizada_en, atencion_realizada: true });
  expect(result).toEqual(saved); expect(result.id_medico).toBe(original.id_medico);
  expect(result.ultimo_cambio.id_actor).not.toBe(result.id_medico);
});
it.each([undefined, false, null, 0, 1, 'true', '1', [], {}])('declaración %s no es true estricto: no CSRF ni PATCH', async declaracion => {
  await expect(marcarAtendida(entrada({ declaracion }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Recepcionista', 'Paciente', 'Otro', undefined])('rol %s no registra atención aunque se manipule elegibilidad', async rol => {
  await expect(marcarAtendida(entrada({ rol }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Atendida', 'Cancelada', 'Inasistencia'])('estado %s no se reabre', async estado => {
  await expect(marcarAtendida(entrada({ original: { ...atencionElegible(), estado } }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['CITA_ATENCION_ANTICIPADA', 'CITA_TRANSICION_NO_PERMITIDA'])('impedimento %s bloquea incluso con acción true', async code => {
  const original = atencionElegible(); original.impedimentos.marcar_atendida = [code];
  await expect(marcarAtendida(entrada({ original }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each([confirmarLlegada, registrarInasistencia])('habilitar atención no relaja roles/estado de otras escrituras', async escribir => {
  await expect(escribir(entrada({ rol: 'Médico' }))).rejects.toThrow();
  const original = atencionElegible('Administrador', 'Confirmada');
  original.acciones.confirmar_llegada = original.acciones.registrar_inasistencia = true;
  original.impedimentos.confirmar_llegada = original.impedimentos.registrar_inasistencia = [];
  await expect(escribir(entrada({ original }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it('conserva versión máxima, IDs grandes y microsegundos sin reloj autoritativo de navegador', async () => {
  const original = { ...atencionElegible(), id_medico: '9007199254740993', version_programacion: 2147483647,
    actualizada_en: '2026-11-01T02:45:00.123456Z', consultado_en: '2026-11-03T04:00:00.000000Z' };
  saved = atencion(original); const result = await marcarAtendida(entrada({ original }));
  expect(result.version_programacion).toBe(2147483647); expect(result.actualizada_en).toBe('2026-11-01T02:45:00.123457Z');
  expect(result.id_medico).toBe(original.id_medico);
});
it.each(['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio', 'codigo_cita', 'inicio_at', 'fin_at',
  'duracion_minutos', 'modalidad', 'sede_atencion', 'consultorio_atencion', 'estado', 'version_programacion', 'actualizada_en', 'ultimo_cambio'])('200 inválido en %s no confirma éxito', async campo => {
  saved[campo] = null; await expect(marcarAtendida(entrada())).rejects.toMatchObject({ asistenciaEnviada: true });
});
it.each(['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio', 'codigo_cita', 'inicio_at', 'fin_at',
  'duracion_minutos', 'sede_atencion', 'consultorio_atencion', 'version_programacion'])('200 con programación modificada en %s es incierto', async campo => {
  saved[campo] = typeof saved[campo] === 'number' ? saved[campo] + 1 : campo.endsWith('_at') ? '2026-11-01T12:30:00.000000Z' : '88';
  await expect(marcarAtendida(entrada())).rejects.toMatchObject({ asistenciaEnviada: true });
});
it.each([['operacion', 'LLEGADA'], ['operacion', 'INASISTENCIA'], ['id_actor', '2'], ['realizado_en', 'sin-fecha']])('cambio %s=%s no acredita atención', async (campo, valor) => {
  saved.ultimo_cambio[campo] = valor; await expect(marcarAtendida(entrada())).rejects.toMatchObject({ asistenciaEnviada: true });
});
it('proyecta mínimos sin clínica/declaración/avisos ni autoría falsa', async () => {
  saved = { ...saved, diagnostico: 'NO-SALIR', atencion_realizada: true, recordatorios: 'NO-SALIR',
    ultimo_cambio: { ...saved.ultimo_cambio, motivo: 'NO-SALIR' } };
  const result = await marcarAtendida(entrada()); expect(JSON.stringify(result)).not.toMatch(/NO-SALIR|diagnostico|atencion_realizada|recordatorios/);
});
it('preparación CSRF fallida y señal cancelada nunca envían PATCH', async () => {
  responder = () => Promise.reject(new Error('PRIVADO'));
  await expect(marcarAtendida(entrada())).rejects.toMatchObject({ asistenciaEnviada: false }); expect(calls).toHaveLength(1);
  calls = []; const controller = new AbortController(); controller.abort();
  await expect(marcarAtendida(entrada(), controller.signal)).rejects.toMatchObject({ asistenciaEnviada: false }); expect(calls).toHaveLength(0);
});
