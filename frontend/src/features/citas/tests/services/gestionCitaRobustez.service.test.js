import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { api, avanzarSesion, csrfURL } from '../../../../api/api';
import { cancelarCita, consultarDisponibilidadReprogramacion, reprogramarCita } from '../../services/gestionCita.service';
import { alternativas, cancelada, cita, gestion, reprogramada } from '../utils/gestionFixtures';

const adapter = api.defaults.adapter;
const acciones = [
  ['reprogramar', signal => reprogramarCita({ id: cita.id_cita, vista: alternativas().data, turno: alternativas().data.turnos[0] }, signal), reprogramada],
  ['cancelar', signal => cancelarCita({ id: cita.id_cita, original: gestion(), motivo: 'MOTIVO-PRIVADO' }, signal), cancelada],
];
let calls, respond;
const ok = (config, data = {}) => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {}, data });
beforeEach(() => { calls = []; respond = config => ok(config); api.defaults.adapter = async config => { calls.push(config); return respond(config); }; });
afterEach(() => { api.defaults.adapter = adapter; });

it.each(acciones)('%s: señal abortada desde el inicio no inicia CSRF ni PATCH', async (_, enviar) => {
  const controller = new AbortController(); controller.abort(); const error = await enviar(controller.signal).catch(e => e);
  expect(error.gestionEnviada).toBe(false); expect(calls).toHaveLength(0);
});
it.each(acciones)('%s: abandonar mientras espera CSRF impide que se envíe PATCH al llegar una respuesta tardía', async (_, enviar) => {
  let complete, iniciado; const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => new Promise(resolve => { complete = () => resolve(ok(config)); iniciado(); });
  const controller = new AbortController(); const pending = enviar(controller.signal).catch(e => e);
  // La barrera es el inicio del adapter real, no una espera temporal para inferir orden.
  await started;
  expect(calls).toHaveLength(1); expect(complete).toBeTypeOf('function');
  controller.abort(); complete(); const error = await pending;
  expect(error.gestionEnviada).toBe(false); expect(calls.every(c => c.url === csrfURL)).toBe(true);
});
it.each(acciones)('%s: cambio de época durante CSRF descarta respuesta y no escribe bajo la nueva sesión', async (_, enviar) => {
  let complete, iniciado; const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => new Promise(resolve => { complete = () => resolve(ok(config)); iniciado(); });
  const pending = enviar().catch(e => e); await started;
  avanzarSesion(); complete(); const error = await pending;
  expect(error.code).toBe('SESSION_SUPERSEDED'); expect(error.gestionEnviada).toBe(false); expect(calls).toHaveLength(1);
});
it.each(acciones)('%s: abortar un PATCH ya enviado no se presenta como rollback ni como éxito', async (_, enviar, resultado) => {
  let complete, iniciado;
  const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => config.url === csrfURL ? ok(config) : new Promise(resolve => { complete = () => resolve(ok(config, { data: resultado() })); iniciado(); });
  const controller = new AbortController(); const pending = enviar(controller.signal).catch(e => e); await started;
  controller.abort(); complete(); const error = await pending;
  expect(error.gestionEnviada).toBe(true); expect(error.code).toBe('ERR_CANCELED'); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(acciones)('%s: cambio de época después del PATCH no publica la respuesta de otra sesión', async (_, enviar, resultado) => {
  let complete, iniciado; const started = new Promise(resolve => { iniciado = resolve; });
  respond = config => config.url === csrfURL ? ok(config) : new Promise(resolve => { complete = () => resolve(ok(config, { data: resultado() })); iniciado(); });
  const pending = enviar().catch(e => e); await started; avanzarSesion(); complete(); const error = await pending;
  expect(error.code).toBe('SESSION_SUPERSEDED'); expect(error.gestionEnviada).toBe(true); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each(acciones.flatMap(a => [400, 401, 403, 404, 405, 408, 409, 419, 422, 429, 499, 500, 502, 503, 504].map(status => [...a, status])))('%s: rechazo HTTP posterior no produce replay (%#)', async (_, enviar, resultado, status) => {
  respond = config => {
    if (config.url === csrfURL) return ok(config);
    throw new AxiosError('SQL-PRIVADO', undefined, config, null, { status, headers: {}, data: { error: { code: 'DESCONOCIDO', message: 'NO-SALIR' } } });
  };
  const error = await enviar().catch(e => e); expect(error.response.status).toBe(status); expect(error.gestionEnviada).toBe(true);
  expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('GET de alternativas ignora enlaces de paginación externos y conserva solo meta calculable', async () => {
  respond = config => ok(config, { ...alternativas(), links: { next: 'https://ajeno.example.test?token=NO-SALIR' } });
  const result = await consultarDisponibilidadReprogramacion({ id: cita.id_cita, original: gestion(), fecha: '2026-11-02' });
  expect(result).not.toHaveProperty('links'); expect(JSON.stringify(result)).not.toContain('NO-SALIR'); expect(calls).toHaveLength(1);
});
