import { afterEach, beforeEach, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { confirmarLlegada, consultarAsistenciaCita, consultarContextoAsistencia } from '../../services/asistenciaCita.service';
import { asistencia, cita, detalle, llegada } from '../utils/asistenciaFixtures';

const adapter = api.defaults.adapter;
let calls, body, detail, saved, responder;
const entrada = patch => ({ id: cita.id_cita, rol: 'Administrador', original: asistencia(), declaracion: true, idActor: '1', ...patch });
beforeEach(() => {
  calls = []; body = asistencia(); detail = detalle('Administrador'); saved = llegada();
  responder = config => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {},
    data: { data: config.method === 'patch' ? saved : config.url.endsWith('/asistencia') ? body : detail } });
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Recepcionista', 'Médico'])('%s consulta contrato real sin query/cuerpo y mantiene originales', async rol => {
  body = asistencia(rol);
  const signal = new AbortController().signal;
  const result = await consultarAsistenciaCita({ id: cita.id_cita, rol, extra: 'NO-SALIR' }, signal);
  expect(result).toEqual(body); expect(result.id_cita).toBe('9223372036854775807');
  expect(result.actualizada_en).toBe('2026-10-26T12:00:00.123456Z');
  expect(calls).toHaveLength(1); expect(calls[0]).toMatchObject({ method: 'get', url: `/citas/${cita.id_cita}/asistencia`, signal, sessionBound: true });
  expect(calls[0].params).toBeUndefined(); expect(calls[0].data).toBeUndefined();
});
it.each(['Paciente', undefined, 'Otro'])('rol %s no consulta asistencia ni contexto', async rol => {
  await expect(consultarAsistenciaCita({ id: cita.id_cita, rol })).rejects.toThrow();
  await expect(consultarContextoAsistencia({ id: cita.id_cita, rol })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['0', '01', '-1', '9223372036854775808', 17, null, undefined, '1/asistencia'])('ID %s no envía HTTP', async id => {
  await expect(consultarAsistenciaCita({ id, rol: 'Administrador' })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Administrador', 'Recepcionista', 'Médico'])('%s coteja identidades y no adopta la programación del detalle', async rol => {
  body = asistencia(rol); detail = detalle(rol); detail.inicio_at = '2026-12-01T12:00:00.000000Z'; detail.fin_at = '2026-12-01T12:15:00.000000Z';
  const result = await consultarContextoAsistencia({ id: cita.id_cita, rol });
  expect(result.asistencia.inicio_at).toBe(body.inicio_at); expect(result.paciente).toEqual({ nombres: 'Paciente', apellidos: 'De prueba' });
  expect(JSON.stringify(result)).not.toMatch(/70000000|MOTIVO-FICTICIO|fecha_nacimiento|documento_identidad/);
});
it.each(['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad'])('contexto rechaza identidad divergente %s', async campo => {
  if (['id_cita', 'codigo_cita'].includes(campo)) detail[campo] = campo === 'id_cita' ? '88' : 'OTRA';
  else detail[{ id_paciente: 'paciente', id_medico: 'medico', id_especialidad: 'especialidad' }[campo]][campo] = '88';
  await expect(consultarContextoAsistencia({ id: cita.id_cita, rol: 'Administrador' })).rejects.toThrow('No se pudo verificar');
});
it('descarta extras de programación, acciones y último cambio', async () => {
  body = { ...body, diagnostico: 'NO-SALIR', acciones: { ...body.acciones, editar: true }, ultimo_cambio: { ...llegada().ultimo_cambio, motivo: 'NO-SALIR' } };
  const result = await consultarAsistenciaCita({ id: cita.id_cita, rol: 'Administrador' });
  expect(JSON.stringify(result)).not.toMatch(/NO-SALIR|editar/);
});
it.each([
  ['ausente', null], ['ID numérico', { id_medico: 7 }], ['ID distinto', { id_cita: '7' }], ['estado', { estado: 'Suspendida' }],
  ['modalidad', { modalidad: 'Virtual' }], ['duración', { duracion_minutos: 0 }], ['versión', { version_programacion: '1' }],
  ['desborde', { version_programacion: 2147483648 }], ['timestamp', { actualizada_en: '2026-10-26T12:00:00Z' }],
  ['fecha imposible', { inicio_at: '2026-02-30T12:00:00.000000Z' }], ['intervalo', { fin_at: '2026-01-01T12:00:00.000000Z' }],
  ['zona', { zona_horaria: 'UTC' }], ['reloj', { consultado_en: undefined }], ['acciones', { acciones: null }],
  ['impedimentos planos', { impedimentos: [] }], ['último cambio', { ultimo_cambio: undefined }], ['sede', { sede_atencion: '' }],
])('respuesta inválida %s no se publica', async (_, patch) => {
  body = patch === null ? null : { ...body, ...patch };
  await expect(consultarAsistenciaCita({ id: cita.id_cita, rol: 'Administrador' })).rejects.toThrow('No se pudo verificar la asistencia');
});
it.each(['confirmar_llegada', 'registrar_inasistencia', 'marcar_atendida'])('rechaza incoherencia/impedimento desconocido/duplicado en %s', async key => {
  for (const codes of [['SQL-PRIVADO'], ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_TRANSICION_NO_PERMITIDA'], ['ACCESO_DENEGADO']]) {
    body = asistencia(); body.impedimentos[key] = codes; body.acciones[key] = false;
    await expect(consultarAsistenciaCita({ id: cita.id_cita, rol: 'Administrador' })).rejects.toThrow();
  }
  body = asistencia(); body.acciones[key] = !body.acciones[key];
  await expect(consultarAsistenciaCita({ id: cita.id_cita, rol: 'Administrador' })).rejects.toThrow();
});
it.each(['Médico', 'Recepcionista'])('%s no acepta acciones otorgadas por respuesta contradictoria', async rol => {
  body = asistencia(rol); const key = rol === 'Médico' ? 'confirmar_llegada' : 'marcar_atendida';
  body.acciones[key] = true; body.impedimentos[key] = [];
  await expect(consultarAsistenciaCita({ id: cita.id_cita, rol })).rejects.toThrow();
});
it.each(['Administrador', 'Recepcionista'])('%s envía solo originales y presencia true después de CSRF; proyección cerrada', async rol => {
  saved.motivo = 'NO-SALIR'; const signal = new AbortController().signal;
  const result = await confirmarLlegada(entrada({ rol, original: asistencia(rol), estado: 'Confirmada', id_medico: '99' }), signal);
  expect(calls).toHaveLength(2); expect(calls[0]).toMatchObject({ method: 'get', url: csrfURL, sessionBound: true, signal });
  expect(calls[1]).toMatchObject({ method: 'patch', url: `/citas/${cita.id_cita}/llegada`, sessionBound: true, signal });
  expect(JSON.parse(calls[1].data)).toEqual({ version_programacion_original: 1,
    actualizada_en_original: asistencia().actualizada_en, presencia_verificada: true });
  expect(result.estado).toBe('Confirmada'); expect(result.version_programacion).toBe(1); expect(JSON.stringify(result)).not.toContain('NO-SALIR');
});
it('versión máxima y timestamp de microsegundo se conservan sin Number/Date sobre originales', async () => {
  const original = asistencia('Administrador', { version_programacion: 2147483647, actualizada_en: '2026-11-01T02:20:00.000000Z' });
  saved = llegada(original); await confirmarLlegada(entrada({ original }));
  expect(JSON.parse(calls[1].data).version_programacion_original).toBe(2147483647);
  expect(JSON.parse(calls[1].data).actualizada_en_original).toBe(original.actualizada_en);
});
it.each([false, undefined, null, 'true', 1, [], {}])('declaración %s no hace CSRF ni PATCH', async declaracion => {
  await expect(confirmarLlegada(entrada({ declaracion }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Paciente', 'Médico', undefined])('rol %s no puede enviar llegada', async rol => {
  await expect(confirmarLlegada(entrada({ rol }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['id_cita', 'id_medico', 'id_paciente', 'id_consultorio', 'id_especialidad', 'codigo_cita', 'inicio_at', 'fin_at', 'duracion_minutos',
  'modalidad', 'sede_atencion', 'consultorio_atencion', 'version_programacion', 'actualizada_en', 'estado', 'ultimo_cambio'])('200 inválido en %s es posterior a envío, no éxito', async campo => {
  saved[campo] = null;
  await expect(confirmarLlegada(entrada())).rejects.toMatchObject({ asistenciaEnviada: true }); expect(calls).toHaveLength(2);
});
it.each(['operacion', 'id_actor'])('cambio con %s incorrecto no confirma esta llegada', async campo => {
  saved.ultimo_cambio[campo] = 'OTRO'; await expect(confirmarLlegada(entrada())).rejects.toMatchObject({ asistenciaEnviada: true });
});
it('fallo CSRF y cancelación antes del PATCH nunca envían escritura', async () => {
  responder = config => Promise.reject(new AxiosError('PRIVADO', undefined, config));
  await expect(confirmarLlegada(entrada())).rejects.toMatchObject({ asistenciaEnviada: false }); expect(calls).toHaveLength(1);
  calls = []; const controller = new AbortController(); controller.abort();
  await expect(confirmarLlegada(entrada(), controller.signal)).rejects.toMatchObject({ asistenciaEnviada: false }); expect(calls).toHaveLength(0);
});
