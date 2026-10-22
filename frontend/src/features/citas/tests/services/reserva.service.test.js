import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { listarEspecialidadesReserva, listarMedicosReserva, consultarDisponibilidad } from '../../services/reserva.service';
import { propuesta, seleccion } from '../utils/reservaFixtures';

const original = api.defaults.adapter;
const id = '9223372036854775807';
let calls;
beforeEach(() => {
  calls = [];
  api.defaults.adapter = vi.fn(async config => {
    calls.push(config);
    const data = config.url.endsWith('/disponibilidad')
      ? { ...propuesta, medico: { ...propuesta.medico, id_medico: config.params.id_medico }, especialidad: { ...propuesta.especialidad, id_especialidad: config.params.id_especialidad }, fecha: config.params.fecha, paciente_reservable: true, turnos: [] } : [];
    return { config, status: 200, headers: {}, data: { data, meta: { total: 0, current_page: 1, last_page: 1 } } };
  });
});
afterEach(() => { api.defaults.adapter = original; });
it('catálogo específico de especialidades filtra campos, trim y conserva paginación/cancelación', async () => {
  const signal = new AbortController().signal;
  const result = await listarEspecialidadesReserva({ nombre: ' Clínica_% ', page: 2, per_page: 10, activa: false }, signal);
  expect(calls[0]).toMatchObject({ method: 'get', url: '/reservas/especialidades', params: { nombre: 'Clínica_%', page: 2, per_page: 10 }, signal, sessionBound: true });
  expect(result).toEqual({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } });
});
it('omite filtros vacíos sin pedir catálogo administrativo', async () => {
  await listarEspecialidadesReserva({ nombre: '   ' });
  expect(calls[0].params).toEqual({ page: 1, per_page: 20 });
  await listarMedicosReserva({ id_especialidad: id, nombre: '', consultorio: '   ' });
  expect(calls[1].params).toEqual({ id_especialidad: id, page: 1, per_page: 20 });
});
it('Médicos conserva BIGINT textual y filtros de nombre/consultorio sin campos de administración', async () => {
  await listarMedicosReserva({ id_especialidad: id, nombre: ' Nombre ', consultorio: ' SALA_% ', page: 3, per_page: 5, activo: false, id_usuario: '1' });
  expect(calls[0].url).toBe('/reservas/medicos');
  expect(calls[0].params).toEqual({ id_especialidad: id, nombre: 'Nombre', consultorio: 'SALA_%', page: 3, per_page: 5 });
});
it('disponibilidad propia omite id_paciente y conserva fecha civil literal sin UTC', async () => {
  const signal = new AbortController().signal;
  await consultarDisponibilidad({ id_especialidad: id, id_medico: '9007199254740993', fecha: '9999-12-31', propuesta: 'NO', estado: 'Confirmada' }, signal);
  expect(calls[0].params).toEqual({ id_especialidad: id, id_medico: '9007199254740993', fecha: '9999-12-31', page: 1, per_page: 20 });
  expect(calls[0].signal).toBe(signal); expect(calls[0].sessionBound).toBe(true);
});
it('Recepción puede enviar ficha autorizada textual sin CI ni cuenta', async () => {
  await consultarDisponibilidad({ id_especialidad: '1', id_medico: '2', id_paciente: id, fecha: '2026-11-01', page: 2, per_page: 12, documento_identidad: 'NO' });
  expect(calls[0].params).toEqual({ id_especialidad: '1', id_medico: '2', id_paciente: id, fecha: '2026-11-01', page: 2, per_page: 12 });
});
it.each(['0', '-1', '1/ruta', '9223372036854775808', undefined])('rechaza selección inválida %s antes del HTTP', async value => {
  await expect(listarMedicosReserva({ id_especialidad: value })).rejects.toThrow();
  await expect(consultarDisponibilidad({ id_especialidad: '1', id_medico: value })).rejects.toThrow();
  expect(calls).toHaveLength(0);
});
it('no convierte null de Paciente en selección propia', async () => {
  await expect(consultarDisponibilidad({ id_especialidad: '1', id_medico: '2', id_paciente: null })).rejects.toThrow();
  expect(calls).toHaveLength(0);
});
it('error de lectura no reintenta ni provoca POST/CSRF', async () => {
  api.defaults.adapter = vi.fn(async () => { throw new Error('Sin red'); });
  await expect(listarEspecialidadesReserva()).rejects.toThrow();
  expect(api.defaults.adapter).toHaveBeenCalledTimes(1);
});
it.each([null, { data: null }, { data: {}, meta: { current_page: 1, last_page: 1, total: 1 } }, { data: [{ id_especialidad: '1' }], meta: { current_page: 1, last_page: 1, total: 1 } }])('catálogo malformado es fallo seguro, no excepción de render', async data => {
  api.defaults.adapter = async config => ({ config, status: 200, headers: {}, data });
  await expect(listarEspecialidadesReserva()).rejects.toThrow('No se pudo verificar');
});
it.each(['fecha', 'medico', 'paciente_reservable', 'turnos', 'zona_horaria', 'duracion_minutos'])('disponibilidad con campo %s inválido no se publica', async campo => {
  const d = { ...propuesta, fecha: seleccion.fecha, paciente_reservable: true, turnos: [] };
  d[campo] = null;
  api.defaults.adapter = async config => ({ config, status: 200, headers: {}, data: { data: d, meta: { current_page: 1, last_page: 1, total: 0 } } });
  await expect(consultarDisponibilidad(seleccion)).rejects.toThrow('No se pudo verificar');
});
