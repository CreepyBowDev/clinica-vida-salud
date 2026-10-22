import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { proponerReserva, confirmarReserva, consultarResultadoReserva, normalizarMotivo } from '../../services/reserva.service';
import { propuesta, cita, seleccion } from '../utils/reservaFixtures';

const original = api.defaults.adapter;
let calls, task;
const payload = { propuesta: propuesta.propuesta, motivo_breve: '0' };
const ok = (config, data, status = 200) => ({ config, status, headers: {}, data });
beforeEach(() => {
  calls = []; task = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    if (task) return task(config);
    if (config.url === csrfURL) return ok(config, '');
    if (config.url === '/reservas/propuesta') return ok(config, { data: propuesta });
    if (config.url === '/reservas/resultado') return ok(config, { data: { registrada: true, cita } });
    return ok(config, { data: cita, meta: { repetida: false } }, 201);
  };
});
afterEach(() => { api.defaults.adapter = original; });
it('propuesta usa whitelist, CSRF y misma selección con segundos sin paginación', async () => {
  const p = await proponerReserva({ ...seleccion, id_paciente: '3', page: 9, estado: 'Confirmada' });
  expect(calls[0].url).toBe(csrfURL); expect(calls[1]).toMatchObject({ url: '/reservas/propuesta', sessionBound: true, method: 'post' });
  expect(JSON.parse(calls[1].data)).toEqual({ ...seleccion, id_paciente: '3' }); expect(p).toEqual(propuesta);
});
it('propuesta propia omite ficha y no recalcula revisión', async () => {
  await proponerReserva(seleccion); expect(JSON.parse(calls[1].data)).not.toHaveProperty('id_paciente');
  expect(JSON.parse(calls[1].data).revision_turno).toBe(seleccion.revision_turno);
});
it('confirma únicamente sobre exacto y motivo revisado conservando meta en raíz', async () => {
  const result = await confirmarReserva({ ...payload, id_paciente: '99', estado: 'Confirmada', duracion_minutos: 1 });
  expect(JSON.parse(calls[1].data)).toEqual(payload); expect(result).toEqual({ data: cita, meta: { repetida: false } });
});
it('resultado envía solo propuesta, sin motivo ni identificadores', async () => {
  expect(await consultarResultadoReserva({ ...payload, id_cita: '9' })).toEqual({ registrada: true, cita });
  expect(JSON.parse(calls[1].data)).toEqual({ propuesta: propuesta.propuesta });
});
it.each([['', null], ['   ', null], [' 0 ', '0'], [' texto ', 'texto'], [null, null]])('normaliza motivo %s sin perder cero', (value, esperado) => {
  expect(normalizarMotivo(value)).toBe(esperado);
});
it.each(['a'.repeat(501), 'salto\nde línea', ['no'], 'control\u0000'])('rechaza motivo inválido sin HTTP', async motivo_breve => {
  await expect(proponerReserva({ ...seleccion, motivo_breve })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it('distingue falla previa de CSRF y no envía POST', async () => {
  task = () => { throw new Error('Sin red'); };
  await expect(confirmarReserva(payload)).rejects.toMatchObject({ reservaEnviada: false }); expect(calls).toHaveLength(1);
});
it('red tras POST se marca enviada sin reintentar', async () => {
  task = c => { if (c.url === csrfURL) return ok(c, ''); throw new AxiosError('Sin red'); };
  await expect(confirmarReserva(payload)).rejects.toMatchObject({ reservaEnviada: true }); expect(calls).toHaveLength(2);
});
it('respuesta 200 repetida devuelve cita vigente sin restaurar Programada', async () => {
  task = c => c.url === csrfURL ? ok(c, '') : ok(c, { data: { ...cita, estado: 'Cancelada' }, meta: { repetida: true } });
  expect((await confirmarReserva(payload)).data.estado).toBe('Cancelada');
});
it('resultado ausente respeta false sin crear ni exigir cita', async () => {
  task = c => c.url === csrfURL ? ok(c, '') : ok(c, { data: { registrada: false } });
  expect(await consultarResultadoReserva(payload)).toEqual({ registrada: false }); expect(calls.map(c => c.url)).toEqual([csrfURL, '/reservas/resultado']);
});
it.each([{ data: null }, { data: cita }, { data: { ...cita, estado: 'Inventado' }, meta: { repetida: false } }, { data: cita, meta: { repetida: true } }])('respuesta de creación inválida no se presenta como éxito', async body => {
  task = c => c.url === csrfURL ? ok(c, '') : ok(c, body, 201);
  await expect(confirmarReserva(payload)).rejects.toMatchObject({ reservaEnviada: true }); expect(calls).toHaveLength(2);
});
it('propuesta malformada no permite revisar ni confirmar', async () => {
  task = c => c.url === csrfURL ? ok(c, '') : ok(c, { data: { ...propuesta, consultorio: null } });
  await expect(proponerReserva(seleccion)).rejects.toThrow(); expect(calls).toHaveLength(2);
});
it('propuesta de otro inicio no sustituye el turno elegido y no envía la referencia local', async () => {
  await expect(proponerReserva({ ...seleccion, inicioEsperado: '2026-11-01T12:15:01.000000Z' })).rejects.toThrow();
  expect(JSON.parse(calls[1].data)).not.toHaveProperty('inicioEsperado');
});
it.each(['confirmar', 'resultado'])('respuesta de otro Paciente en %s no se presenta como propia', async tipo => {
  const input = { ...payload, pacienteEsperado: '99' };
  await expect((tipo === 'confirmar' ? confirmarReserva : consultarResultadoReserva)(input)).rejects.toMatchObject({ reservaEnviada: true });
  expect(JSON.parse(calls[1].data)).not.toHaveProperty('pacienteEsperado');
});
it('resultado malformado sin booleano no significa ausencia confirmada', async () => {
  task = c => c.url === csrfURL ? ok(c, '') : ok(c, { data: { registrada: 'false' } });
  await expect(consultarResultadoReserva(payload)).rejects.toThrow(); expect(calls).toHaveLength(2);
});
