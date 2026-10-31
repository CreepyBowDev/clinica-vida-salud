import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, avanzarSesion, obtenerEpocaSesion } from '../../../../api/api';
import { ROLES } from '../../../../constants/roles';
import { consultarSolicitud, listarSolicitudes, obtenerArchivo, proyectarSolicitud, MAX_RESULTADO } from '../../services/laboratorio.service';
import { disponible, listado, solicitud } from '../utils/laboratorioFixtures';

const { ADMINISTRADOR: A, MEDICO: M, PACIENTE: P, RECEPCIONISTA: R } = ROLES;
let get;
beforeEach(() => { get = vi.spyOn(api, 'get'); });
afterEach(() => vi.restoreAllMocks());
describe('lecturas de laboratorio y proyección mínima', () => {
  it.each([M, P])('%s usa listado propio sin IDs inventados ni query vacía', async rol => {
    get.mockResolvedValue({ status: 200, data: listado([solicitud()]) });
    const d = await listarSolicitudes({ rol, estado: '', ignorado: 'secreto' });
    expect(d.solicitudes[0].id_solicitud).toBe('71');
    expect(get).toHaveBeenCalledWith('/solicitudes-laboratorio', expect.objectContaining({ params: { page: 1, per_page: 20 }, sessionBound: true, sessionEpochExpected: obtenerEpocaSesion() }));
  });
  it('Admin exige contexto y no permite IDs ajenos en el filtro personal', async () => {
    await expect(listarSolicitudes({ rol: A })).rejects.toThrow();
    await expect(listarSolicitudes({ rol: P, paciente: '21' })).rejects.toThrow();
    await expect(listarSolicitudes({ rol: R })).rejects.toThrow();
    expect(get).not.toHaveBeenCalled();
    get.mockResolvedValue({ status: 200, data: listado([solicitud()]) });
    await listarSolicitudes({ rol: A, paciente: '21', estado: 'Pendiente', fecha_desde: '2026-10-01', fecha_hasta: '2026-10-30' });
    expect(get.mock.calls[0][1].params).toEqual({ page: 1, per_page: 20, id_paciente: '21', estado: 'Pendiente', fecha_desde: '2026-10-01', fecha_hasta: '2026-10-30' });
  });
  it('consulta usa ruta real y descarta edición de alta futura', async () => {
    const d = listado([]); d.data = { id_consulta: '44', solicitudes: [], edicion: { acciones: { solicitar: true } } };
    get.mockResolvedValue({ status: 200, data: d });
    const result = await listarSolicitudes({ rol: A, consulta: '44' });
    expect(get.mock.calls[0][0]).toBe('/consultas/44/solicitudes-laboratorio'); expect(result).not.toHaveProperty('edicion');
  });
  it.each(['0', '01', '1e2', '9223372036854775808', 71])('rechaza ID no canónico %s sin HTTP', async id => {
    await expect(consultarSolicitud({ rol: A, id })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
  });
  it('conserva BIGINT textual y descarta extras del servidor', async () => {
    const id = '9223372036854775807'; get.mockResolvedValue({ status: 200, data: { data: solicitud({ id_solicitud: id, ruta_privada: 'privado' }) } });
    const d = await consultarSolicitud({ rol: A, id }); expect(d.id_solicitud).toBe(id); expect(d).not.toHaveProperty('ruta_privada'); expect(d).not.toHaveProperty('edicion');
  });
  it('Paciente no incorpora contexto, autores o revisiones internos aunque aparezcan como extras', () => {
    const d = proyectarSolicitud(disponible(), P, true);
    expect(Object.keys(d).sort()).toEqual(['id_solicitud', 'id_consulta', 'estudio_solicitado', 'solicitada_en', 'paciente', 'medico', 'estado', 'resultado'].sort());
    expect(JSON.stringify(d)).not.toContain('revisada_por');
    expect(() => proyectarSolicitud(solicitud({ estado: 'En revisión' }), P)).toThrow();
  });
  it('interno descarga no publicada desde vigente sin inventar MIME/tamaño', () => {
    const d = proyectarSolicitud(solicitud({ estado: 'En revisión', edicion: { vigente: { id_version_resultado: '81', numero_version: 2 }, ultima_revision: null, acciones: { revisar: true } } }), M, true);
    expect(d.resultado).toBeNull(); expect(d.documento).toEqual({ id_version_resultado: '81', numero_version: 2 }); expect(d).not.toHaveProperty('acciones');
  });
  it.each([
    { page: 0 }, { per_page: 101 }, { fecha_desde: '2026-02-30' }, { fecha_desde: '2026-10-30', fecha_hasta: '2026-10-01' }, { estado: 'En revisión' },
  ])('valida filtros %j', async filtros => {
    await expect(listarSolicitudes({ rol: P, ...filtros })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
  });
  it.each(['paciente', 'consulta', 'paginacion', 'duplicados', 'estado', 'mime', 'version'])('rechaza respuesta incoherente: %s', async caso => {
    const row = disponible(), data = listado([row]);
    if (caso === 'paciente') row.paciente.id_paciente = '22';
    if (caso === 'consulta') { data.data = { id_consulta: '45', solicitudes: [row] }; }
    if (caso === 'paginacion') data.meta.total = 2;
    if (caso === 'duplicados') { data.data.push(row); data.meta.total = 2; }
    if (caso === 'estado') row.estado = 'Cancelada';
    if (caso === 'mime') row.resultado.tipo_mime = 'text/html';
    if (caso === 'version') row.resultado.numero_version = 0;
    get.mockResolvedValue({ status: 200, data });
    await expect(listarSolicitudes({ rol: A, ...(caso === 'consulta' ? { consulta: '44' } : { paciente: '21' }) })).rejects.toThrow();
  });
  it('retira una respuesta de otra época o petición abortada', async () => {
    let resolve; get.mockImplementation(() => new Promise(r => { resolve = r; }));
    const request = consultarSolicitud({ rol: P, id: '71' }); avanzarSesion(); resolve({ status: 200, data: { data: solicitud() } });
    await expect(request).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
    const c = new AbortController(), second = consultarSolicitud({ rol: P, id: '71' }, c.signal); c.abort(); resolve({ status: 200, data: { data: solicitud() } });
    await expect(second).rejects.toThrow();
  });
});

describe('transporte de documento protegido', () => {
  it.each([['application/pdf', 'pdf'], ['image/jpeg', 'jpg'], ['image/png', 'png']])('descarga %s por versión exacta con nombre sintético', async (mime, ext) => {
    const blob = new Blob(['ficticio'], { type: mime });
    get.mockResolvedValue({ status: 200, data: blob, headers: { 'content-type': mime, 'content-length': '8', 'content-disposition': 'malicioso' } });
    const d = await obtenerArchivo({ solicitud: '71', version: '81' });
    expect(d).toEqual({ blob, nombre: `resultado-81.${ext}` });
    expect(get).toHaveBeenCalledWith('/solicitudes-laboratorio/71/archivo', expect.objectContaining({ params: { id_version_resultado: '81' }, responseType: 'blob', sessionBound: true }));
    expect(get).toHaveBeenCalledTimes(1);
  });
  it.each(['json', 'html', 'vacio', 'excesivo', 'length', 'mime', 'status', 'metadatos'])('nunca entrega archivo inválido %s', async caso => {
    const r = { status: 200, data: new Blob(['ficticio'], { type: 'application/pdf' }), headers: { 'content-type': 'application/pdf' } };
    if (caso === 'json' || caso === 'html') { const mime = caso === 'json' ? 'application/json' : 'text/html'; r.data = new Blob(['tecnico'], { type: mime }); r.headers['content-type'] = mime; }
    if (caso === 'vacio') r.data = new Blob([], { type: 'application/pdf' });
    if (caso === 'excesivo') r.data = new Blob([new Uint8Array(MAX_RESULTADO + 1)], { type: 'application/pdf' });
    if (caso === 'length') r.headers['content-length'] = '9';
    if (caso === 'mime') r.headers['content-type'] = 'image/png';
    if (caso === 'status') r.status = 206;
    get.mockResolvedValue(r);
    await expect(obtenerArchivo({ solicitud: '71', version: '81', resultado: caso === 'metadatos' ? { ...disponible().resultado, tamano_bytes: 10 } : null })).rejects.toThrow();
  });
  it.each([401, 403, 404, 409, 419, 422, 429, 500])('normaliza Blob JSON HTTP %s sin propagar texto privado', async status => {
    const blob = new Blob(['{}'], { type: 'application/json' });
    blob.text = async () => JSON.stringify({ error: { code: 'LAB_EDICION_OBSOLETA', message: 'privado', details: { ruta: 'privado' } } });
    get.mockRejectedValue({ response: { status, data: blob, headers: { 'retry-after': '2' } } });
    try { await obtenerArchivo({ solicitud: '71', version: '81' }); expect.fail(); } catch (e) {
      expect(e.response).toMatchObject({ status, data: { error: { code: 'LAB_EDICION_OBSOLETA' } } }); expect(JSON.stringify(e.response)).not.toContain('privado');
    }
  });
  it('descarta cambio de sesión durante lectura asíncrona del error Blob', async () => {
    let resolve; const blob = new Blob(['{}'], { type: 'application/json' }); blob.text = () => new Promise(r => { resolve = r; });
    get.mockRejectedValue({ response: { status: 409, data: blob } });
    const promise = obtenerArchivo({ solicitud: '71', version: '81' }); await vi.waitFor(() => expect(resolve).toBeDefined());
    avanzarSesion(); resolve('{}'); await expect(promise).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
  });
});
