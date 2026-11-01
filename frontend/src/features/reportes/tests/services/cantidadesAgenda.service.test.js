import { afterEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { consultarCantidadesCitas, consultarAgendaDiaria } from '../../services/reporte.service';
import { cantidadesReporte, agendaReporte } from '../utils/cantidadesAgendaFixtures';
import { medico } from '../utils/reporteFixtures';

afterEach(() => vi.restoreAllMocks());
it('cantidades usa el agregado específico, sin paginación, orden, estado ni catálogos operativos', async () => {
  const params = { desde: '2026-01-01', hasta: '2027-01-01', id_medico: medico.id_medico, id_especialidad: '5' };
  const value = cantidadesReporte(params); value.data.diagnostico = 'PRIVADO'; value.meta.secreto = 'PRIVADO';
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: value }), controller = new AbortController();
  const result = await consultarCantidadesCitas(params, controller.signal);
  expect(get).toHaveBeenCalledExactlyOnceWith('/reportes/citas/cantidades', { params, signal: controller.signal, sessionBound: true });
  expect(result.data).toEqual({ atendidas: 10, canceladas: 20, inasistencias: 7, total: 37 });
  expect(result.meta).not.toHaveProperty('total'); expect(result.meta).not.toHaveProperty('current_page'); expect(JSON.stringify(result)).not.toContain('PRIVADO');
});
it('cantidades admite fecha e IDs opcionales vacíos, conserva cuatro ceros explícitos', async () => {
  const params = { fecha: '2026-10-31' }, counts = { atendidas: 0, canceladas: 0, inasistencias: 0, total: 0 };
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: cantidadesReporte(params, counts) });
  expect((await consultarCantidadesCitas({ ...params, id_medico: '', id_especialidad: '' })).data).toEqual(counts);
  expect(get.mock.calls[0][1].params).toEqual(params);
});
it.each([{ page: 1 }, { per_page: 20 }, { orden: 'fecha_asc' }, { estado: 'Atendida' }, { activo: '1' }, { id_paciente: '21' },
  { id_medico: 12 }, { id_especialidad: '01' }, { fecha: '' }, { fecha: '2026-02-30' }, { desde: '2026-10-01', hasta: '2026-10-31' }])('cantidades rechaza filtros no aplicables/inválidos %j', async invalid => {
  const get = vi.spyOn(api, 'get');
  await expect(consultarCantidadesCitas({ fecha: '2026-10-31', ...invalid })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
});
it.each(['faltante', 'negativo', 'decimal', 'array', 'reporte', 'periodo', 'estados', 'fecha', 'medico'])('cantidades rechaza respuesta incoherente: %s', async tipo => {
  const params = { fecha: '2026-10-31' }, value = cantidadesReporte(params);
  if (tipo === 'faltante') delete value.data.atendidas;
  else if (tipo === 'negativo') value.data.total = -1;
  else if (tipo === 'decimal') value.data.canceladas = 1.5;
  else if (tipo === 'array') value.data = [];
  else if (tipo === 'reporte') value.meta.reporte = 'agenda-diaria';
  else if (tipo === 'periodo') value.meta.periodo.criterio = 'interseccion_diaria';
  else if (tipo === 'estados') value.meta.filtros.estados = ['Programada'];
  else if (tipo === 'fecha') value.meta.filtros.fecha = '2026-11-02';
  else value.meta.filtros.id_medico = '12';
  vi.spyOn(api, 'get').mockResolvedValue({ data: value }); await expect(consultarCantidadesCitas(params)).rejects.toThrow();
});
it('agenda diaria usa ruta administrativa, orden fijo implícito, bigint textual y proyección mínima', async () => {
  const params = { fecha: '2026-10-31', id_medico: medico.id_medico, estado: 'Confirmada', page: 2, per_page: 20 }, value = agendaReporte(params, 21);
  const row = value.data[0]; row.motivo_breve = 'PRIVADO'; row.paciente.documento_identidad = 'PRIVADO';
  row.consultorio_atencion = 'Sala histórica conservada'; row.duracion_minutos = 30;
  row.inicio_at = '2026-10-31T03:45:00.000000Z'; row.fin_at = '2026-10-31T04:15:00.000000Z';
  const get = vi.spyOn(api, 'get').mockResolvedValue({ data: value }), controller = new AbortController();
  const result = await consultarAgendaDiaria(params, controller.signal);
  expect(get).toHaveBeenCalledExactlyOnceWith('/reportes/agenda-diaria', { params, signal: controller.signal, sessionBound: true });
  expect(result.data[0]).toMatchObject({ inicio_at: row.inicio_at, fin_at: row.fin_at, estado: 'Confirmada', consultorio_atencion: 'Sala histórica conservada', duracion_minutos: 30 });
  expect(result.meta.total).toBe(21); expect(result.meta.medico.id_medico).toBe(medico.id_medico); expect(JSON.stringify(result)).not.toContain('PRIVADO');
});
it.each(['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])('agenda admite estado opcional %s', async estado => {
  const params = { fecha: '2026-10-31', id_medico: medico.id_medico, estado };
  vi.spyOn(api, 'get').mockResolvedValue({ data: agendaReporte(params) });
  expect((await consultarAgendaDiaria(params)).data[0].estado).toBe(estado);
});
it.each([0, 21])('agenda conserva vacío con total %s fuera del final', async total => {
  const params = { fecha: '2026-10-31', id_medico: medico.id_medico, page: 3 };
  vi.spyOn(api, 'get').mockResolvedValue({ data: agendaReporte(params, total) });
  const result = await consultarAgendaDiaria(params); expect(result.data).toEqual([]); expect(result.meta.total).toBe(total);
});
it.each([{ id_medico: undefined }, { fecha: undefined }, { id_medico: 12 }, { id_medico: '01' }, { orden: 'fecha_asc' }, { id_especialidad: '5' },
  { desde: '2026-10-01', hasta: '2026-10-31' }, { estado: 'Todos' }, { estado: ['Programada'] }, { page: 0 }, { per_page: 101 }])('agenda rechaza filtros incompletos/no aplicables %j', async invalid => {
  const get = vi.spyOn(api, 'get');
  await expect(consultarAgendaDiaria({ fecha: '2026-10-31', id_medico: medico.id_medico, ...invalid })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
});
it.each(['reporte', 'periodo', 'contexto', 'medico', 'fila', 'estado', 'total'])('agenda rechaza contexto/proyección incoherente: %s', async tipo => {
  const params = { fecha: '2026-10-31', id_medico: medico.id_medico, estado: 'Confirmada' }, value = agendaReporte(params);
  if (tipo === 'reporte') value.meta.reporte = 'citas/por-medico';
  else if (tipo === 'periodo') value.meta.periodo.criterio = 'inicio_en_periodo';
  else if (tipo === 'contexto') value.meta.filtros.fecha = '2026-11-02';
  else if (tipo === 'medico') value.meta.medico = { ...medico, id_medico: '12' };
  else if (tipo === 'fila') value.data[0].medico = { ...medico, id_medico: '12' };
  else if (tipo === 'estado') value.data[0].estado = 'Programada';
  else value.meta.total = 0;
  vi.spyOn(api, 'get').mockResolvedValue({ data: value }); await expect(consultarAgendaDiaria(params)).rejects.toThrow();
});
it.each([401, 403, 404, 419, 422, 429, 500])('ambos servicios propagan HTTP %s sin fallback ni reintentos', async status => {
  const error = { response: { status } }, get = vi.spyOn(api, 'get').mockRejectedValue(error);
  await expect(consultarCantidadesCitas({ fecha: '2026-10-31' })).rejects.toBe(error);
  await expect(consultarAgendaDiaria({ fecha: '2026-10-31', id_medico: medico.id_medico })).rejects.toBe(error); expect(get).toHaveBeenCalledTimes(2);
});
