import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { consultarDisponibilidadReprogramacion, reprogramarCita } from '../../services/gestionCita.service';
import { alternativas, cita, gestion, reprogramada } from '../utils/gestionFixtures';

const originalAdapter = api.defaults.adapter;
let calls, body, saved, fail;
const entrada = () => ({ id: cita.id_cita, vista: alternativas().data, turno: alternativas().data.turnos[0], motivo: 'NO-ENVIAR' });
beforeEach(() => {
  calls = []; body = alternativas(); saved = reprogramada(); fail = null;
  api.defaults.adapter = async config => {
    calls.push(config); if (fail) { const error = fail(config); if (error) throw error; }
    return { config, status: config.url === csrfURL ? 204 : 200, headers: {}, data: config.method === 'patch' ? { data: saved } : body };
  };
});
afterEach(() => { api.defaults.adapter = originalAdapter; });

it('alternativas envía solo fecha/página, deriva recursos del servidor y no exige paciente_reservable', async () => {
  body.data.paciente_reservable = false; body.data.motivo = 'NO-SALIR'; body.data.turnos[0].token = 'NO-SALIR';
  const signal = new AbortController().signal;
  const result = await consultarDisponibilidadReprogramacion({ id: cita.id_cita, fecha: '2026-11-02', original: gestion(), id_paciente: '88', estado: 'Cancelada' }, signal);
  expect(calls[0]).toMatchObject({ method: 'get', url: `/citas/${cita.id_cita}/disponibilidad-reprogramacion`, signal, sessionBound: true,
    params: { fecha: '2026-11-02', page: 1, per_page: 12 } });
  expect(calls[0].data).toBeUndefined(); expect(JSON.stringify(result)).not.toContain('NO-SALIR');
  expect(result.data).not.toHaveProperty('paciente_reservable'); expect(result.data.cita_original.actualizada_en).toBe(gestion().actualizada_en);
});
it.each([{ page: 2, per_page: 12, total: 13 }, { page: 1, per_page: 12, total: 0 }, { page: 3, per_page: 12, total: 1 }])('paginación válida %j conserva resultados/vacío legítimo', async filters => {
  body = alternativas(gestion(), filters);
  const result = await consultarDisponibilidadReprogramacion({ id: cita.id_cita, fecha: '2026-11-02', original: gestion(), ...filters });
  expect(result.meta).toEqual(body.meta);
});
it.each([{ fecha: '2026-02-30' }, { page: 0 }, { per_page: 101 }, { id: 17 }, { original: { ...gestion(), id_cita: '2' } }])('entrada inválida %j no consulta', async patch => {
  await expect(consultarDisponibilidadReprogramacion({ id: cita.id_cita, fecha: '2026-11-02', original: gestion(), ...patch })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['version_programacion', 'actualizada_en', 'id_consultorio', 'inicio_at'])('original con %s cambiado no se adopta silenciosamente', async key => {
  body.data.cita_original[key] = key === 'version_programacion' ? 2 : key === 'id_consultorio' ? '99' : '2026-10-30T12:00:00.000000Z';
  await expect(consultarDisponibilidadReprogramacion({ id: cita.id_cita, fecha: '2026-11-02', original: gestion() })).rejects.toThrow();
});
it.each(['fecha', 'zona', 'paciente', 'medico', 'especialidad', 'total', 'pagina', 'duracion', 'hora', 'revision', 'duplicado', 'fin', 'mismo-inicio'])('respuesta de alternativas inválida por %s se rechaza', async tipo => {
  const d = body.data;
  if (tipo === 'fecha') d.fecha = '2026-11-03';
  if (tipo === 'zona') d.zona_horaria = 'UTC';
  if (tipo === 'paciente') d.paciente.id_paciente = '88';
  if (tipo === 'medico') d.medico.id_medico = '88';
  if (tipo === 'especialidad') d.especialidad.id_especialidad = '88';
  if (tipo === 'total') body.meta.total = 2;
  if (tipo === 'pagina') body.meta.current_page = 2;
  if (tipo === 'duracion') d.duracion_minutos = 15;
  if (tipo === 'hora') d.turnos[0].hora_inicio = '12:00:00';
  if (tipo === 'revision') d.turnos[0].revision_turno = 'TOKEN-INVALIDO';
  if (tipo === 'duplicado') { d.turnos.push(d.turnos[0]); body.meta.total = 2; }
  if (tipo === 'fin') d.turnos[0].fin_at = d.turnos[0].inicio_at;
  if (tipo === 'mismo-inicio') d.turnos[0].inicio_at = gestion().inicio_at;
  await expect(consultarDisponibilidadReprogramacion({ id: cita.id_cita, fecha: '2026-11-02', original: gestion() })).rejects.toThrow();
});
it('PATCH precedido de CSRF conserva originales precisos, usa turno revisado y no envía recurso/rol/motivo/propuesta', async () => {
  const result = await reprogramarCita(entrada());
  expect(calls.map(c => c.method)).toEqual(['get', 'patch']); expect(calls[0].url).toBe(csrfURL);
  expect(calls[1].url).toBe(`/citas/${cita.id_cita}/reprogramacion`);
  expect(JSON.parse(calls[1].data)).toEqual({ fecha: '2026-11-02', hora_inicio: '08:00:00', revision_turno: 'a'.repeat(64),
    version_programacion_original: 1, actualizada_en_original: '2026-10-26T12:00:00.123456Z' });
  expect(calls.every(c => c.sessionBound)).toBe(true); expect(result).toEqual(saved);
  expect(result).not.toHaveProperty('acciones'); expect(result).not.toHaveProperty('zona_horaria');
});
it('Confirmada acepta resultado Programada, misma cita y versión siguiente sin exigir metadatos de GET', async () => {
  const vista = alternativas({ ...gestion(), estado: 'Confirmada' }).data;
  saved = reprogramada(vista); saved.motivo = 'NO-SALIR'; saved.ultimo_cambio.token = 'NO-SALIR';
  const result = await reprogramarCita({ id: cita.id_cita, vista, turno: vista.turnos[0] });
  expect(result.estado).toBe('Programada'); expect(result.id_cita).toBe(cita.id_cita); expect(JSON.stringify(result)).not.toContain('NO-SALIR');
});
it('no envía un turno que no pertenece a la vista o el mismo inicio', async () => {
  await expect(reprogramarCita({ ...entrada(), turno: { ...entrada().turno, revision_turno: 'b'.repeat(64) } })).rejects.toThrow();
  await expect(reprogramarCita({ ...entrada(), turno: { ...entrada().turno, inicio_at: gestion().inicio_at } })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio', 'inicio_at', 'duracion_minutos', 'estado', 'version_programacion', 'actualizada_en', 'ultimo_cambio'])('PATCH 200 con %s no verificable marca envío incierto', async key => {
  saved[key] = key.startsWith('id_') ? '88' : key === 'version_programacion' ? 1 : key === 'duracion_minutos' ? 15 : key === 'estado' ? 'Confirmada'
    : key === 'actualizada_en' ? gestion().actualizada_en : key === 'ultimo_cambio' ? null : gestion().inicio_at;
  const error = await reprogramarCita(entrada()).catch(e => e);
  expect(error.gestionEnviada).toBe(true); expect(error.message).toBe('No se pudo verificar la gestión de la cita.');
});
it('fallo CSRF anterior al PATCH marca no enviado y no ejecuta escritura', async () => {
  fail = () => new Error('RED'); const error = await reprogramarCita(entrada()).catch(e => e);
  expect(error.gestionEnviada).toBe(false); expect(calls).toHaveLength(1);
});
it.each([409, 419, 422, 429, 500, undefined])('fallo PATCH %s conserva estado HTTP y nunca reintenta', async status => {
  fail = config => config.method === 'patch' ? new AxiosError('PRIVADO', undefined, config, null, status ? { status } : undefined) : null;
  const error = await reprogramarCita(entrada()).catch(e => e);
  expect(error.gestionEnviada).toBe(true); expect(error.response?.status).toBe(status); expect(calls).toHaveLength(2);
});
