import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { cancelarCita, normalizarMotivoCancelacion } from '../../services/gestionCita.service';
import { cancelada, cita, gestion } from '../utils/gestionFixtures';

const adapter = api.defaults.adapter;
let calls, saved, respond;
beforeEach(() => {
  calls = []; saved = cancelada(); respond = config => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {}, data: { data: saved } });
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each([undefined, null, '', '   '])('motivo opcional %j viaja como null con originales exactos, sin recursos ni propuesta', async motivo => {
  const result = await cancelarCita({ id: cita.id_cita, original: gestion(), motivo, estado: 'Atendida', id_medico: '88', propuesta: 'NO-ENVIAR' });
  expect(calls.map(c => c.method)).toEqual(['get', 'patch']); expect(calls[0].url).toBe(csrfURL);
  expect(calls[1]).toMatchObject({ url: `/citas/${cita.id_cita}/cancelacion`, sessionBound: true });
  expect(calls.every(c => c.sessionBound)).toBe(true); expect(calls[1].params).toBeUndefined();
  expect(JSON.parse(calls[1].data)).toEqual({ version_programacion_original: 1, actualizada_en_original: '2026-10-26T12:00:00.123456Z', motivo: null });
  expect(result).toEqual(saved); expect(result).not.toHaveProperty('acciones');
});
it('normaliza motivo privado y descarta extras tanto del resultado como del cambio', async () => {
  saved.motivo = 'NO-SALIR'; saved.ultimo_cambio.motivo = 'NO-SALIR'; saved.diagnostico = 'NO-SALIR';
  const result = await cancelarCita({ id: cita.id_cita, original: gestion(), motivo: '  Motivo privado ficticio  ' });
  expect(JSON.parse(calls[1].data).motivo).toBe('Motivo privado ficticio'); expect(JSON.stringify(result)).not.toContain('NO-SALIR');
});
it.each(['Programada', 'Confirmada'])('cancela %s conservando intervalo/duración/ubicación/versión y sin consultar disponibilidad', async estado => {
  const original = { ...gestion(), estado, version_programacion: 2147483647 }; saved = cancelada(original);
  const result = await cancelarCita({ id: cita.id_cita, original });
  for (const key of ['inicio_at', 'fin_at', 'duracion_minutos', 'sede_atencion', 'consultorio_atencion', 'id_consultorio', 'version_programacion']) expect(result[key]).toBe(original[key]);
  expect(result.estado).toBe('Cancelada'); expect(calls).toHaveLength(2); expect(calls.some(c => /disponibilidad|reservas/.test(c.url))).toBe(false);
});
it('500 puntos de código Unicode son válidos, no se recortan ni cuentan como 1000 UTF-16', async () => {
  const motivo = '😀'.repeat(500); expect(normalizarMotivoCancelacion(motivo)).toBe(motivo);
  await cancelarCita({ id: cita.id_cita, original: gestion(), motivo }); expect(JSON.parse(calls[1].data).motivo).toBe(motivo);
});
it.each(['x'.repeat(501), 'a\nb', 'a\tb', 'a\u0000b', 'a\u200db', 15, {}, ['motivo']])('motivo inválido no envía HTTP (%#)', async motivo => {
  await expect(cancelarCita({ id: cita.id_cita, original: gestion(), motivo })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['0', '01', '9223372036854775808', 17, null])('ID inválido %s no hace CSRF ni PATCH', async id => {
  await expect(cancelarCita({ id, original: gestion() })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Cancelada', 'Atendida', 'Inasistencia'])('estado final %s no se reabre con envío', async estado => {
  await expect(cancelarCita({ id: cita.id_cita, original: { ...gestion(), estado } })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio', 'inicio_at', 'fin_at', 'duracion_minutos', 'sede_atencion',
  'consultorio_atencion', 'version_programacion', 'estado', 'actualizada_en', 'ultimo_cambio'])('200 con %s alterado no confirma cancelación y marca enviado', async key => {
  saved[key] = key.startsWith('id_') ? '88' : key === 'version_programacion' ? 2 : key === 'duracion_minutos' ? 20 : key === 'estado' ? 'Programada'
    : key === 'actualizada_en' ? gestion().actualizada_en : key === 'ultimo_cambio' ? null : key.endsWith('_at') ? '2026-12-01T12:00:00.000000Z' : 'OTRO';
  const failure = await cancelarCita({ id: cita.id_cita, original: gestion() }).catch(e => e);
  expect(failure.gestionEnviada).toBe(true); expect(failure.message).toBe('No se pudo verificar la gestión de la cita.');
});
it.each(['actor', 'operacion', 'fecha', 'id'])('último cambio %s inválido no se publica', async tipo => {
  saved.ultimo_cambio[tipo === 'actor' ? 'id_actor' : tipo === 'operacion' ? 'operacion' : tipo === 'id' ? 'id_cambio_cita' : 'realizado_en'] = 'PRIVADO';
  const failure = await cancelarCita({ id: cita.id_cita, original: gestion() }).catch(e => e); expect(failure.gestionEnviada).toBe(true);
});
it('CSRF fallido marca no enviado y no ejecuta PATCH', async () => {
  respond = () => { throw new Error('RED'); };
  const failure = await cancelarCita({ id: cita.id_cita, original: gestion() }).catch(e => e);
  expect(failure.gestionEnviada).toBe(false); expect(calls).toHaveLength(1);
});
it.each([401, 403, 404, 409, 419, 422, 429, 500, undefined])('PATCH rechazado %s no reintenta y preserva status', async status => {
  respond = config => {
    if (config.method === 'patch') throw new AxiosError('PRIVADO', undefined, config, null, status ? { status } : undefined);
    return { config, status: 204, headers: {}, data: {} };
  };
  const failure = await cancelarCita({ id: cita.id_cita, original: gestion() }).catch(e => e);
  expect(failure.gestionEnviada).toBe(true); expect(failure.response?.status).toBe(status); expect(calls).toHaveLength(2);
});
