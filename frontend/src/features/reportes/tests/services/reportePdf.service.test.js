import { afterEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api, avanzarSesion, obtenerEpocaSesion, escucharSesionInvalidada } from '../../../../api/api';
import { descargarReportePdf } from '../../services/reportePdf.service';

const original = api.defaults.adapter;
const pdf = () => new Blob(['%PDF-1.7\nPDF ficticio de prueba, no documento Laravel\n%%EOF\n'], { type: 'application/pdf' });
const respuesta = (data = pdf(), headers = {}) => ({ data, headers: { 'content-type': 'application/pdf', ...headers } });
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); });

it.each([
  ['programadas', 'citas/programadas', { fecha: '2026-11-07', id_medico: '', orden: 'fecha_desc' }],
  ['por-medico', 'citas/por-medico', { desde: '2026-10-01', hasta: '2026-11-07', id_medico: '9007199254740993', estado: 'Confirmada', orden: 'fecha_asc' }],
  ['por-especialidad', 'citas/por-especialidad', { fecha: '2026-11-07', id_especialidad: '5', orden: 'fecha_desc' }],
  ['por-estado', 'citas/por-estado', { fecha: '2026-11-07', estado: 'Inasistencia' }],
  ['pacientes-registrados', 'pacientes/registrados', { desde: '2026-10-01', hasta: '2026-11-07', activo: '0', orden: 'nombre_asc' }],
  ['cantidades', 'citas/cantidades', { fecha: '2026-11-07', id_medico: '7', id_especialidad: '5' }],
  ['agenda-diaria', 'agenda-diaria', { fecha: '2026-11-07', id_medico: '7', estado: 'Cancelada' }],
])('%s usa recurso PDF, filtros completos sin paginación y sesión compartida', async (tipo, recurso, filtros) => {
  const nombre = `reporte-${recurso.replaceAll('/', '-')}-20261107-123000.pdf`;
  const get = vi.spyOn(api, 'get').mockResolvedValue(respuesta(pdf(), { 'content-disposition': `attachment; filename="${nombre}"` }));
  const controller = new AbortController();
  const result = await descargarReportePdf(tipo, { ...filtros, page: 3, per_page: 100 }, controller.signal);
  expect(result.nombre).toBe(nombre); expect(result.blob).toBeInstanceOf(Blob);
  expect(get).toHaveBeenCalledExactlyOnceWith(`/reportes/${recurso}/pdf`, {
    params: Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== '')), signal: controller.signal,
    responseType: 'blob', sessionBound: true, sessionEpochExpected: obtenerEpocaSesion(), timeout: 120000,
    headers: { Accept: 'application/pdf, application/json' },
  });
  expect(api.defaults.withCredentials).toBe(true); expect(api.defaults.withXSRFToken).toBe(true);
});
it.each([undefined, 'attachment; filename="../../secreto.pdf"', 'attachment; filename="paciente.pdf"',
  'attachment; filename="reporte-citas-programadas-20261107-123000.pdf.exe"', 'attachment; filename="<script>.pdf"'])('nombre no confiable %s usa alternativa fija sin datos personales', async disposition => {
  vi.spyOn(api, 'get').mockResolvedValue(respuesta(pdf(), { 'content-disposition': disposition }));
  expect((await descargarReportePdf('programadas', {})).nombre).toBe('reporte-citas-programadas.pdf');
});
it.each([
  [new Blob(['{}'], { type: 'application/json' }), 'application/json'],
  [new Blob(['{"error":"privado"}'], { type: 'application/pdf' }), 'application/pdf'],
  [new Blob(['%PDF-1.7\nsin cierre'], { type: 'application/pdf' }), 'application/pdf'],
  [new Blob([], { type: 'application/pdf' }), 'application/pdf'],
  [pdf(), 'text/html'], [pdf(), undefined], ['%PDF-1.7\n%%EOF', 'application/pdf'],
  [new Blob(['%PDF-1.7\n%%EOF'], { type: 'text/html' }), 'application/pdf'],
  [new Blob([new Uint8Array(8 * 1024 * 1024 + 1)], { type: 'application/pdf' }), 'application/pdf'],
])('rechaza contenido/tipo/tamaño inválido %# sin entregar archivo', async (data, mime) => {
  vi.spyOn(api, 'get').mockResolvedValue({ data, headers: { 'content-type': mime } });
  await expect(descargarReportePdf('programadas', {})).rejects.toThrow('PDF verificable');
});
it.each([401, 403, 419, 422, 429, 500])('traduce JSON Blob HTTP %s sin filtrar mensaje técnico ni reintentar', async status => {
  const get = vi.spyOn(api, 'get').mockRejectedValue({ response: { status, headers: { 'retry-after': '2' },
    data: new Blob([JSON.stringify({ error: { message: 'SQL PRIVADO' } })], { type: 'application/json' }) } });
  const error = await descargarReportePdf('programadas', {}).catch(e => e);
  expect(error.status).toBe(status); expect(error.message).not.toContain('SQL PRIVADO');
  if (status === 429) expect(error.retryAfter).toBe(2);
  expect(get).toHaveBeenCalledTimes(1);
});
it('exceso 422 muestra exportacion del contrato y nunca ofrece parcial', async () => {
  const message = 'El reporte supera el límite de 500 filas. Acota los filtros y vuelve a exportar. No se generó un PDF parcial.';
  vi.spyOn(api, 'get').mockRejectedValue({ response: { status: 422, data: new Blob([JSON.stringify({ error: {
    code: 'VALIDACION_ERROR', details: { exportacion: [message] },
  } })]) } });
  await expect(descargarReportePdf('programadas', {})).rejects.toThrow(message);
});
it.each(['html', 'json roto', 'red'])('error %s no revela cuerpo ni produce PDF', async mode => {
  vi.spyOn(api, 'get').mockRejectedValue(mode === 'red' ? new Error('RED PRIVADA') : { response: { status: 500, data: new Blob([mode]) } });
  await expect(descargarReportePdf('programadas', {})).rejects.toThrow(/No pudimos/);
});
it.each(['abort', 'sesion'])('descarta %s incluso si el transporte ignora cancelación', async mode => {
  let resolve;
  vi.spyOn(api, 'get').mockImplementation(() => new Promise(r => { resolve = r; }));
  const controller = new AbortController(), request = descargarReportePdf('programadas', {}, controller.signal);
  if (mode === 'abort') controller.abort(); else avanzarSesion();
  resolve(respuesta()); await expect(request).rejects.toMatchObject({ code: 'ERR_CANCELED' });
});
it('descarta sesión cambiada durante la lectura asíncrona del Blob', async () => {
  vi.spyOn(api, 'get').mockResolvedValue(respuesta());
  const request = descargarReportePdf('programadas', {});
  await Promise.resolve(); avanzarSesion();
  await expect(request).rejects.toMatchObject({ code: 'ERR_CANCELED' });
});
it('401 con Axios/interceptor real invalida la sesión actual aun con cuerpo Blob', async () => {
  const invalidated = vi.fn(), unsubscribe = escucharSesionInvalidada(invalidated);
  api.defaults.adapter = async config => { throw new AxiosError('privado', undefined, config, null,
    { config, status: 401, headers: {}, data: new Blob(['{}'], { type: 'application/json' }) }); };
  try {
    await expect(descargarReportePdf('programadas', {})).rejects.toMatchObject({ code: 'ERR_CANCELED' });
    expect(invalidated).toHaveBeenCalledOnce();
  } finally { unsubscribe(); }
});
