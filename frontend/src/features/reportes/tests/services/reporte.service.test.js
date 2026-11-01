import { afterEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { consultarCitasProgramadas, consultarCitasPorMedico, consultarCitasPorEspecialidad, consultarCitasPorEstado } from '../../services/reporte.service';
import { reporte, medico } from '../utils/reporteFixtures';

afterEach(() => vi.restoreAllMocks());
it('usa Axios existente, GET exclusivo, IDs textuales y proyección administrativa sin secretos', async () => {
  const params = { fecha: '2026-10-31', id_medico: medico.id_medico, id_especialidad: '5', orden: 'fecha_desc', page: 1, per_page: 20 };
  const value = reporte(params); value.data[0].diagnostico = 'NO MOSTRAR'; value.data[0].paciente.documento_identidad = 'NO MOSTRAR';
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: value }), controller = new AbortController();
  const result = await consultarCitasProgramadas(params, controller.signal);
  expect(get).toHaveBeenCalledWith('/reportes/citas/programadas', { params, signal: controller.signal, sessionBound: true });
  expect(result.data[0].medico.id_medico).toBe(medico.id_medico);
  expect(JSON.stringify(result)).not.toContain('NO MOSTRAR'); expect(result.data[0].estado).toBe('Programada');
});
it('omite filtros opcionales vacíos y conserva total real fuera del final', async () => {
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: reporte({ page: 3 }, 21) });
  const result = await consultarCitasProgramadas({ fecha: '2026-10-31', page: 3, id_medico: '', id_especialidad: '' });
  expect(get.mock.calls[0][1].params).toEqual({ fecha: '2026-10-31', orden: 'fecha_asc', page: 3, per_page: 20 });
  expect(result.data).toEqual([]); expect(result.meta.total).toBe(21);
});
it.each([{}, { fecha: '2026-02-30' }, { fecha: '' }, { estado: 'Programada' }, { desde: '2026-10-30' }, { id_medico: 12 }, { id_medico: '01' }, { id_especialidad: '9223372036854775808' }, { orden: 'nombre_asc' }, { page: 0 }, { per_page: 101 }])('rechaza entrada inválida/no aplicable %j sin petición', async invalid => {
  const get = vi.spyOn(api, 'get');
  const args = Object.keys(invalid).length ? { fecha: '2026-10-31', ...invalid } : invalid;
  await expect(consultarCitasProgramadas(args)).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
});
it.each(['Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])('no acepta %s como Programada ni la oculta contando localmente', async estado => {
  const value = reporte(); value.data[0].estado = estado; vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  await expect(consultarCitasProgramadas({ fecha: '2026-10-31' })).rejects.toThrow();
});
it.each(['fecha', 'id_medico', 'orden', 'estado'])('rechaza contexto de filtros distinto: %s', async key => {
  const value = reporte(); value.meta.filtros[key] = 'otro'; vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  await expect(consultarCitasProgramadas({ fecha: '2026-10-31' })).rejects.toThrow();
});
it('propaga errores HTTP sin reintentos', async () => {
  const error = { response: { status: 422 } }, get = vi.spyOn(api, 'get').mockRejectedValue(error);
  await expect(consultarCitasProgramadas({ fecha: '2026-10-31' })).rejects.toBe(error); expect(get).toHaveBeenCalledTimes(1);
});

const nuevosReportes = [
  ['por-medico', consultarCitasPorMedico, { id_medico: medico.id_medico }],
  ['por-especialidad', consultarCitasPorEspecialidad, { id_especialidad: '5' }],
  ['por-estado', consultarCitasPorEstado, { estado: 'Confirmada' }],
];
it.each(nuevosReportes)('%s consulta el contrato propio con fecha o rango y filtros combinados', async (recurso, load) => {
  const get = vi.spyOn(api, 'get');
  for (const periodo of [{ fecha: '2026-10-31' }, { desde: '2026-01-01', hasta: '2027-01-01' }]) {
    const params = { ...periodo, id_medico: medico.id_medico, id_especialidad: '5', estado: 'Confirmada', orden: 'fecha_desc', page: 2, per_page: 20 };
    get.mockResolvedValue({ data: reporte(params, 21, recurso) });
    const result = await load(params);
    expect(get).toHaveBeenLastCalledWith(`/reportes/citas/${recurso}`, { params, signal: undefined, sessionBound: true });
    expect(result.meta.total).toBe(21); expect(result.data[0].estado).toBe('Confirmada'); expect(result.data[0].medico.id_medico).toBe(medico.id_medico);
  }
});
it.each(nuevosReportes)('%s exige su selección y rechaza filtros ajenos antes de consultar', async (recurso, load, seleccion) => {
  const get = vi.spyOn(api, 'get');
  for (const params of [{ fecha: '2026-10-31' }, { fecha: '2026-10-31', ...seleccion, id_paciente: '21' },
    { fecha: '2026-10-31', ...seleccion, desde: '2026-10-30', hasta: '2026-10-31' },
    { desde: '2026-01-01', hasta: '2027-01-02', ...seleccion }]) {
    await expect(load(params)).rejects.toThrow();
  }
  expect(get).not.toHaveBeenCalled();
});
it.each(['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])('por-estado admite exactamente el estado %s', async estado => {
  const params = { fecha: '2026-10-31', estado };
  vi.spyOn(api, 'get').mockResolvedValue({ data: reporte(params, 1, 'por-estado') });
  expect((await consultarCitasPorEstado(params)).data[0].estado).toBe(estado);
});
it.each(['programada', 'Todos', ['Programada'], '', null])('por-estado rechaza estado inválido %j', async estado => {
  const get = vi.spyOn(api, 'get');
  await expect(consultarCitasPorEstado({ fecha: '2026-10-31', estado })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
});
it.each(nuevosReportes)('%s no atribuye al filtro un resultado de otro reporte', async (recurso, load, seleccion) => {
  vi.spyOn(api, 'get').mockResolvedValue({ data: reporte({ fecha: '2026-10-31', ...seleccion }, 1, 'programadas') });
  await expect(load({ fecha: '2026-10-31', ...seleccion })).rejects.toThrow();
});
it.each(nuevosReportes.slice(0, 2))('%s admite todos los estados al omitir filtro y conserva vacío con total real', async (recurso, load, seleccion) => {
  const params = { fecha: '2026-10-31', ...seleccion, page: 3 }, value = reporte(params, 21, recurso);
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: value });
  const result = await load(params);
  expect(get.mock.calls[0][1].params).not.toHaveProperty('estado'); expect(result.data).toEqual([]); expect(result.meta.total).toBe(21);
});
