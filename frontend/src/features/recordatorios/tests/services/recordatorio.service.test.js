import { afterEach, expect, it, vi } from 'vitest';
import { api, csrfURL, avanzarSesion, obtenerEpocaSesion } from '../../../../api/api';
import { listarRecordatorios, consultarProximaCita, enviarRecordatorioManual } from '../../services/recordatorio.service';
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

const proxima = { id_cita: '71', revision: 'a'.repeat(64), inicio_at: '2026-11-10T14:30:00.000000Z',
  medico: 'Profesional ficticio', consultorio: 'Consultorio 3', manual_generado: false, preferencia_habilitada: true };
const operacion = 'c6f5b48c-fdd1-4d4d-9b77-34db57794d78';
const resultado = { id_recordatorio: '91', id_cita: '71', aviso_interno: 'Generado', push: 'Pendiente' };
it('consulta próxima cita sin depender de avisos y proyecta solo el contrato', async () => {
  const get = mock({ data: { cita: { ...proxima, secreto: 'NO-CONSERVAR' }, operacion: null } });
  expect(await consultarProximaCita('21')).toEqual({ cita: proxima, operacion: null });
  expect(get).toHaveBeenCalledWith('/recordatorios/proxima-cita', expect.objectContaining({ params: { id_paciente: '21' }, sessionBound: true }));
});
it('CSRF y POST tienen la misma época, cuerpo cerrado y resultado interno distinto del push', async () => {
  const get = mock(null), post = vi.spyOn(api, 'post').mockResolvedValue({ status: 200, data: { data: resultado } });
  expect(await enviarRecordatorioManual('21', proxima, operacion)).toEqual(resultado);
  expect(get).toHaveBeenCalledWith(csrfURL, expect.objectContaining({ sessionBound: true }));
  expect(post).toHaveBeenCalledExactlyOnceWith('/recordatorios/manual', { id_paciente: '21', id_cita: '71', revision: proxima.revision, operacion }, get.mock.calls[0][1]);
});
it.each(['sesión', 'abort'])('no hace POST si cambia %s durante CSRF', async caso => {
  const controller = new AbortController();
  vi.spyOn(api, 'get').mockImplementation(async () => { if (caso === 'sesión') avanzarSesion(); else controller.abort(); return { status: 204 }; });
  const post = vi.spyOn(api, 'post');
  await expect(enviarRecordatorioManual('21', proxima, operacion, controller.signal)).rejects.toMatchObject({ recordatorioEnviado: false });
  expect(post).not.toHaveBeenCalled();
});
it('un timeout de escritura es incierto y nunca dispara un segundo POST', async () => {
  mock(null); const post = vi.spyOn(api, 'post').mockRejectedValue(new Error('timeout'));
  await expect(enviarRecordatorioManual('21', proxima, operacion)).rejects.toMatchObject({ recordatorioEnviado: true });
  expect(post).toHaveBeenCalledTimes(1);
});
it('rechaza próxima cita o resultado de envío malformados sin afirmar éxito', async () => {
  mock({ data: { cita: { ...proxima, revision: 'inválida' }, operacion: null } });
  await expect(consultarProximaCita('21')).rejects.toThrow();
  vi.spyOn(api, 'post').mockResolvedValue({ status: 200, data: { data: { ...resultado, id_cita: '72' } } });
  await expect(enviarRecordatorioManual('21', proxima, operacion)).rejects.toMatchObject({ recordatorioEnviado: true });
});
