import { afterEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { consultarPacientesRegistrados } from '../../services/reporte.service';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';

afterEach(() => vi.restoreAllMocks());
it('consulta solo el reporte específico, mantiene bigint textual y proyecta exactamente los seis campos', async () => {
  const value = pacientesReporte(), paciente = value.data[0];
  Object.assign(paciente, { documento_identidad: 'PRIVADO', telefono: 'PRIVADO', correo_contacto: 'PRIVADO', fecha_nacimiento: 'PRIVADO',
    usuario: { password: 'PRIVADO' }, antecedentes: 'PRIVADO', preferencias: 'PRIVADO' });
  value.meta.usuario = 'PRIVADO';
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: value }), controller = new AbortController();
  const result = await consultarPacientesRegistrados({ fecha: '2026-10-31' }, controller.signal);
  expect(get).toHaveBeenCalledExactlyOnceWith('/reportes/pacientes/registrados', { params: { fecha: '2026-10-31', orden: 'registro_asc', page: 1, per_page: 20 }, signal: controller.signal, sessionBound: true });
  expect(Object.keys(result.data[0])).toEqual(['id_paciente', 'codigo_paciente', 'nombres', 'apellidos', 'activo', 'creado_en']);
  expect(result.data[0].id_paciente).toBe('9007199254740993'); expect(result.data[0].activo).toBe(false);
  expect(JSON.stringify(result)).not.toContain('PRIVADO');
});
it.each(['registro_asc', 'registro_desc', 'nombre_asc'])('envía rango y orden %s sin reordenar ni sumar páginas en cliente', async orden => {
  const params = { desde: '2026-01-01', hasta: '2027-01-01', orden, page: 2, per_page: 20 };
  const value = pacientesReporte(params, 22); value.data.reverse();
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  const result = await consultarPacientesRegistrados(params);
  expect(get.mock.calls[0][1].params).toEqual(params); expect(result.data.map(v => v.id_paciente)).toEqual(value.data.map(v => v.id_paciente));
  expect(result.meta.total).toBe(22);
});
it.each(['0', '1', 0, 1])('serializa activo %j como 0/1 y conserva el booleano normalizado del servidor', async activo => {
  const params = { fecha: '2026-10-31', activo }, get = vi.spyOn(api, 'get').mockResolvedValue({ data: pacientesReporte(params) });
  const result = await consultarPacientesRegistrados(params);
  expect(get.mock.calls[0][1].params.activo).toBe(String(activo)); expect(result.data[0].activo).toBe(String(activo) === '1');
});
it('omitir actividad o seleccionarla vacía incluye inactivos sin exigir CI ni cuenta web', async () => {
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: pacientesReporte() });
  for (const params of [{ fecha: '2026-10-31' }, { fecha: '2026-10-31', activo: '' }]) {
    expect((await consultarPacientesRegistrados(params)).data[0].activo).toBe(false);
    expect(get.mock.calls.at(-1)[1].params).not.toHaveProperty('activo');
  }
});
it.each([0, 21])('vacío o página fuera del final conserva total %s del servidor', async total => {
  const params = { fecha: '2026-10-31', page: 3 }, value = pacientesReporte(params, total);
  vi.spyOn(api, 'get').mockResolvedValue({ data: value }); const result = await consultarPacientesRegistrados(params);
  expect(result.data).toEqual([]); expect(result.meta.total).toBe(total); expect(result.meta.current_page).toBe(3);
});
it.each([
  { fecha: undefined }, { fecha: '2026-02-30' }, { desde: '2026-01-01', hasta: '2026-01-02' },
  { estado: 'Programada' }, { id_medico: '12' }, { id_especialidad: '5' }, { id_paciente: '21' },
  { activo: true }, { activo: null }, { activo: ['0'] }, { activo: 'false' }, { activo: 2 },
  { orden: 'fecha_asc' }, { page: 0 }, { page: 1000001 }, { per_page: 101 },
])('rechaza entradas no aplicables o inválidas %j sin peticiones', async invalid => {
  const get = vi.spyOn(api, 'get');
  await expect(consultarPacientesRegistrados({ fecha: '2026-10-31', ...invalid })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
});
it.each(['reporte', 'fecha', 'activo', 'orden', 'total', 'fila'])('rechaza contrato incoherente: %s', async tipo => {
  const value = pacientesReporte();
  if (tipo === 'reporte') value.meta.reporte = 'citas/programadas';
  else if (tipo === 'total') value.meta.total = 0;
  else if (tipo === 'fila') value.data[0].activo = '0';
  else value.meta.filtros[tipo] = 'INCONSISTENTE';
  vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  await expect(consultarPacientesRegistrados({ fecha: '2026-10-31' })).rejects.toThrow();
});
it('rechaza una fila activa cuando el filtro solicitado es inactivo', async () => {
  const value = pacientesReporte({ activo: '0' }); value.data[0].activo = true;
  vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  await expect(consultarPacientesRegistrados({ fecha: '2026-10-31', activo: '0' })).rejects.toThrow();
});
it.each([401, 403, 404, 419, 422, 429, 500])('propaga HTTP %s sin reintento ni fallback a /pacientes', async status => {
  const error = { response: { status } }, get = vi.spyOn(api, 'get').mockRejectedValue(error);
  await expect(consultarPacientesRegistrados({ fecha: '2026-10-31' })).rejects.toBe(error); expect(get).toHaveBeenCalledTimes(1);
});
