import { afterEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion, obtenerEpocaSesion } from '../../../../api/api';
import { listarRecordatorios } from '../../services/recordatorio.service';
import { aviso, listado } from '../utils/recordatorioFixtures';

afterEach(() => vi.restoreAllMocks());
const filters = { rol: 'Paciente' };
function mock(data = listado()) { return vi.spyOn(api, 'get').mockResolvedValue({ status: 200, data }); }
it('usa Axios compartido, GET ligado a sesión, filtros permitidos y proyección mínima', async () => {
  const data = listado(), signal = new AbortController().signal;
  data.data[0].secreto = 'NO-CONSERVAR'; data.data[0].cita.diagnostico = 'NO-CONSERVAR';
  const get = mock(data), epoca = obtenerEpocaSesion();
  const r = await listarRecordatorios(filters, signal);
  expect(get).toHaveBeenCalledExactlyOnceWith('/recordatorios', { params: { page: 1, per_page: 20 }, signal, sessionBound: true, sessionEpochExpected: epoca });
  expect(r.data[0].id_recordatorio).toBe('9007199254740993'); expect(JSON.stringify(r)).not.toContain('NO-CONSERVAR');
  expect(r.meta).not.toHaveProperty('paciente');
});
it('envía fechas civiles intactas y paginación real', async () => {
  const p = { page: 2, per_page: 1, total: 2, fecha_desde: '2026-10-30', fecha_hasta: '2026-11-01' };
  const get = mock(listado([aviso()], p));
  const { total, ...params } = p;
  await listarRecordatorios({ ...filters, ...params }); expect(get.mock.calls[0][1].params).toEqual(params);
});
it.each([
  { rol: 'Administrador' }, { rol: 'Médico' }, { rol: 'Recepcionista' }, { id_paciente: '21' }, { estado: 'Programada' },
  { page: 0 }, { page: 2147483648 }, { per_page: 101 }, { fecha_desde: '2026-02-30' }, { fecha_desde: '2026-11-01', fecha_hasta: '2026-10-30' },
])('rechaza entrada ajena al contrato sin HTTP: %j', async extra => {
  const get = mock(); await expect(listarRecordatorios({ ...filters, ...extra })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
});
it.each([
  d => { d.meta.zona_horaria = 'UTC'; }, d => { d.meta.current_page = 2; }, d => { d.meta.total = 10; },
  d => { d.meta.filtros.fecha_desde = '2026-10-30'; }, d => { d.data[0].id_recordatorio = 123; },
  d => { d.data[0].cita.inicio_at = '2026-10-31T02:30:00Z'; }, d => { d.data[0].cita.medico = null; },
  d => { d.data[0].cita.estado = 'Cancelada'; }, d => { d.data = null; },
])('rechaza respuesta incompatible sin conservarla', async mutate => {
  const data = listado(); mutate(data); mock(data); await expect(listarRecordatorios(filters)).rejects.toThrow();
});
it('acepta vacío y página fuera del total sin inventar avisos', async () => {
  mock(listado([], { page: 4 })); expect((await listarRecordatorios({ ...filters, page: 4 })).data).toEqual([]);
});
it('descarta respuesta si cambia la época durante la lectura', async () => {
  let resolve;
  vi.spyOn(api, 'get').mockImplementation(() => new Promise(yes => { resolve = yes; }));
  const result = listarRecordatorios(filters); avanzarSesion(); resolve({ status: 200, data: listado() });
  await expect(result).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
});
it('Administrador envía únicamente selector válido y filtros de lectura con Axios ligado a sesión', async () => {
  const get = mock();
  await listarRecordatorios({ rol: 'Administrador', id_paciente: '21' });
  expect(get).toHaveBeenCalledExactlyOnceWith('/recordatorios', expect.objectContaining({
    params: { page: 1, per_page: 20, id_paciente: '21' }, sessionBound: true, sessionEpochExpected: obtenerEpocaSesion(),
  }));
});
it.each([undefined, null, '', '0', 21, '21 OR 1=1'])('rechaza selector administrativo inválido %s sin HTTP', async id_paciente => {
  const get = mock();
  await expect(listarRecordatorios({ rol: 'Administrador', id_paciente })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
});
it('rechaza campos extra del Administrador y respuestas de otro paciente', async () => {
  const get = mock();
  await expect(listarRecordatorios({ rol: 'Administrador', id_paciente: '21', push: true })).rejects.toThrow();
  expect(get).not.toHaveBeenCalled();
  await expect(listarRecordatorios({ rol: 'Administrador', id_paciente: '22' })).rejects.toThrow();
});
