import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { api, avanzarSesion, csrfURL } from '../../../../api/api';
import { confirmarLlegada, registrarInasistencia, marcarAtendida, consultarContextoAsistencia, consultarAsistenciaCita } from '../../services/asistenciaCita.service';
import { asistencia, ausenciaElegible, atencionElegible, llegada, inasistencia, atencion, cita, detalle } from '../utils/asistenciaFixtures';

const adapter = api.defaults.adapter;
const acciones = [
  ['llegada', confirmarLlegada, asistencia, llegada, ['Administrador', 'Recepcionista']],
  ['inasistencia', registrarInasistencia, ausenciaElegible, inasistencia, ['Administrador', 'Recepcionista']],
  ['atencion', marcarAtendida, atencionElegible, atencion, ['Administrador', 'Médico']],
].flatMap(([nombre, enviar, original, resultado, roles]) => roles.map(rol => [nombre, signal => enviar({ id: cita.id_cita, rol, idActor: '1', original: original(rol), declaracion: true }, signal), () => resultado(original(rol)), rol]));
let calls, respond;
const ok = (config, data = {}, status = config.url === csrfURL ? 204 : 200) => ({ config, status, headers: {}, data });
beforeEach(() => { calls = []; respond = config => ok(config); api.defaults.adapter = async config => { calls.push(config); return respond(config); }; });
afterEach(() => { api.defaults.adapter = adapter; });

it.each(acciones)('%s: señal abortada no inicia CSRF/PATCH (%#)', async (_, enviar) => {
  const controller = new AbortController(); controller.abort();
  await expect(enviar(controller.signal)).rejects.toMatchObject({ asistenciaEnviada: false }); expect(calls).toHaveLength(0);
});
it.each(acciones)('%s: abandonar espera CSRF impide PATCH tardío (%#)', async (_, enviar) => {
  let complete, iniciado; const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => new Promise(resolve => { complete = () => resolve(ok(config)); iniciado(); });
  const controller = new AbortController(), pending = enviar(controller.signal).catch(e => e); await started;
  controller.abort(); complete(); const error = await pending; expect(error.asistenciaEnviada).toBe(false); expect(calls).toHaveLength(1);
});
it.each(acciones)('%s: cambio de época durante CSRF impide escribir bajo nueva sesión (%#)', async (_, enviar) => {
  let complete, iniciado; const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => new Promise(resolve => { complete = () => resolve(ok(config)); iniciado(); });
  const pending = enviar().catch(e => e); await started; avanzarSesion(); complete();
  expect(await pending).toMatchObject({ code: 'SESSION_SUPERSEDED', asistenciaEnviada: false }); expect(calls).toHaveLength(1);
});
it.each(acciones)('%s: abortar PATCH ya enviado es resultado no verificado, no rollback (%#)', async (_, enviar, resultado) => {
  let complete, iniciado; const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => config.url === csrfURL ? ok(config) : new Promise(resolve => { complete = () => resolve(ok(config, { data: resultado() })); iniciado(); });
  const controller = new AbortController(), pending = enviar(controller.signal).catch(e => e); await started; controller.abort(); complete();
  expect(await pending).toMatchObject({ code: 'ERR_CANCELED', asistenciaEnviada: true }); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(acciones)('%s: cambio de época tras PATCH descarta respuesta sin replay (%#)', async (_, enviar, resultado) => {
  let complete, iniciado; const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => config.url === csrfURL ? ok(config) : new Promise(resolve => { complete = () => resolve(ok(config, { data: resultado() })); iniciado(); });
  const pending = enviar().catch(e => e); await started; avanzarSesion(); complete();
  expect(await pending).toMatchObject({ code: 'SESSION_SUPERSEDED', asistenciaEnviada: true }); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(acciones.flatMap(a => [400, 401, 403, 404, 405, 408, 409, 419, 422, 429, 499, 500, 502, 503, 504].map(status => [...a, status])))('%s: error HTTP no provoca replay (%#)', async (_, enviar, resultado, rol, status) => {
  respond = config => { if (config.url === csrfURL) return ok(config);
    throw new AxiosError('SQL-PRIVADO', undefined, config, null, { status, headers: {}, data: { error: { code: 'DESCONOCIDO', message: 'NO-SALIR' } } }); };
  const error = await enviar().catch(e => e); expect(error.asistenciaEnviada).toBe(true); expect(error.response.status).toBe(status);
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1); expect(calls).toHaveLength(2);
});
it.each(acciones.flatMap(a => [201, 202, 204, 206].map(status => [...a, status])))('%s: éxito HTTP no contractual nunca se valida como confirmado (%#)', async (_, enviar, resultado, rol, status) => {
  respond = config => config.url === csrfURL ? ok(config) : ok(config, { data: resultado() }, status);
  await expect(enviar()).rejects.toMatchObject({ asistenciaEnviada: true }); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad'])('lecturas concurrentes discordantes en %s no mezclan identidades', async campo => {
  const actual = asistencia(); actual[campo] = campo === 'codigo_cita' ? 'OTRA-CITA' : '88';
  respond = config => ok(config, { data: config.url.endsWith('/asistencia') ? actual : detalle('Administrador') });
  await expect(consultarContextoAsistencia({ id: cita.id_cita, rol: 'Administrador' })).rejects.toThrow(); expect(calls).toHaveLength(2);
});
it('detalle desactualizado solo aporta etiquetas cotejadas, nunca sustituye programación/elegibilidad', async () => {
  const actual = asistencia(), viejo = detalle('Administrador'); viejo.estado = 'Cancelada'; viejo.inicio_at = '2020-01-01T00:00:00.000000Z';
  respond = config => ok(config, { data: config.url.endsWith('/asistencia') ? actual : viejo });
  const result = await consultarContextoAsistencia({ id: cita.id_cita, rol: 'Administrador' });
  expect(result.asistencia.estado).toBe(actual.estado); expect(result.asistencia.inicio_at).toBe(actual.inicio_at);
  expect(JSON.stringify(result)).not.toMatch(/MOTIVO-FICTICIO|70000000|fecha_nacimiento/);
});
it.each(['confirmar_llegada', 'registrar_inasistencia', 'marcar_atendida'].flatMap(accion => ['desconocido', 'contradictorio', 'duplicado'].map(tipo => [accion, tipo])))('%s: respuesta %s falla cerrada sin escrituras', async (accion, tipo) => {
  const body = asistencia();
  if (tipo === 'desconocido') body.impedimentos[accion] = ['NO-SALIR'];
  if (tipo === 'contradictorio') body.acciones[accion] = !body.acciones[accion];
  if (tipo === 'duplicado') { body.acciones[accion] = false; body.impedimentos[accion] = ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_TRANSICION_NO_PERMITIDA']; }
  respond = config => ok(config, { data: body });
  await expect(consultarAsistenciaCita({ id: cita.id_cita, rol: 'Administrador' })).rejects.toThrow(); expect(calls).toHaveLength(1);
});
