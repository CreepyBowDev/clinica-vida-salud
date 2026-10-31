import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, csrfURL, avanzarSesion, obtenerEpocaSesion } from '../../../../api/api';
import { ROLES } from '../../../../constants/roles';
import { consultarConsulta } from '../../../historia-clinica/services/historiaClinica.service';
import { consultarContextoSolicitud, listarSolicitudes, prepararSolicitud, solicitarEstudio } from '../../services/laboratorio.service';
import { contextoSolicitud, solicitud, listado } from '../utils/laboratorioFixtures';
vi.mock('../../../historia-clinica/services/historiaClinica.service', () => ({ consultarConsulta: vi.fn() }));
let get, post, input;
const confirmado = () => ({ status: 201, data: { data: solicitud({ creada_por: '7' }) } });
beforeEach(() => {
  vi.clearAllMocks(); get = vi.spyOn(api, 'get').mockResolvedValue({ status: 204 }); post = vi.spyOn(api, 'post').mockResolvedValue(confirmado());
  input = { id: '41', rol: ROLES.MEDICO, idActor: '7', contexto: contextoSolicitud(obtenerEpocaSesion()), estudio: 'Hemograma de prueba', declaracion: true };
});
afterEach(() => vi.restoreAllMocks());
describe('contexto de CU-24', () => {
  function mockContexto() {
    const c = input.contexto;
    consultarConsulta.mockResolvedValue({ paciente: c.paciente, cita: { id_cita: '41', codigo_cita: c.codigo_cita, medico: c.medico, inicio_at: c.inicio_at, estado: c.estado_cita },
      consulta: { id_consulta: '44', codigo_consulta: 'CON-044', version_vigente: { id_version_consulta: '61', motivo_consulta: 'PRIVADO' } }, antecedentes: { alergias: 'PRIVADO' } });
    get.mockResolvedValue({ status: 200, data: { ...listado([]), data: { id_consulta: '44', solicitudes: [], edicion: c.edicion } } });
  }
  it('lee cita, luego consulta real aun sin estudios; descarta clínica y vincula época', async () => {
    mockContexto(); const d = await consultarContextoSolicitud({ id: '41', rol: ROLES.MEDICO });
    expect(consultarConsulta).toHaveBeenCalledWith({ id: '41', rol: ROLES.MEDICO }, undefined);
    expect(get.mock.calls[0][0]).toBe('/consultas/44/solicitudes-laboratorio'); expect(d.id_consulta).toBe('44'); expect(d.epocaSesion).toBe(obtenerEpocaSesion());
    expect(JSON.stringify(d)).not.toContain('PRIVADO'); expect(d.edicion).toEqual(input.contexto.edicion);
  });
  it.each(['Paciente', 'Recepcionista'])('%s no solicita contexto editable', async rol => {
    await expect(consultarContextoSolicitud({ id: '41', rol })).rejects.toThrow();
    await expect(listarSolicitudes({ consulta: '44', rol, paraSolicitar: true })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
  });
  it('consulta inexistente no genera GET laboral ni alta clínica implícita', async () => {
    consultarConsulta.mockResolvedValue({ consulta: null }); await expect(consultarContextoSolicitud({ id: '41', rol: ROLES.MEDICO })).rejects.toThrow(); expect(get).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled();
  });
  it.each(['original', 'accion', 'impedimento', 'version-cruzada', 'paciente-cruzado'])('rechaza contexto incoherente %s', async tipo => {
    mockContexto(); const d = { status: 200, data: { ...listado([]), data: { id_consulta: '44', solicitudes: [], edicion: structuredClone(input.contexto.edicion) } } };
    if (tipo === 'original') d.data.data.edicion.originales.id_version_consulta = '01';
    if (tipo === 'accion') d.data.data.edicion.acciones.solicitar = false;
    if (tipo === 'impedimento') d.data.data.edicion.impedimentos = ['NO-INVENTAR'];
    if (tipo === 'version-cruzada') d.data.data.edicion.originales.id_version_consulta = '62';
    if (tipo === 'paciente-cruzado') { d.data.data.solicitudes = [solicitud({ paciente: { ...solicitud().paciente, id_paciente: '22' } })]; d.data.meta.total = 1; }
    get.mockResolvedValue(d); await expect(consultarContextoSolicitud({ id: '41', rol: ROLES.MEDICO })).rejects.toThrow();
  });
  it('retiene impedimentos del servidor sin bloquear Atendida por el reloj del navegador', async () => {
    mockContexto(); input.contexto.edicion.acciones.solicitar = false; input.contexto.edicion.impedimentos = ['LAB_ESTADO_NO_PERMITIDO'];
    const d = await consultarContextoSolicitud({ id: '41', rol: ROLES.MEDICO }); expect(d.edicion.acciones.solicitar).toBe(false);
  });
  it('sesión cambiada entre ambas lecturas no inicia la segunda', async () => {
    mockContexto(); consultarConsulta.mockImplementation(async () => { avanzarSesion(); return { consulta: {} }; });
    await expect(consultarContextoSolicitud({ id: '41', rol: ROLES.MEDICO })).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' }); expect(get).not.toHaveBeenCalled();
  });
});
describe('envío único con originales y CSRF', () => {
  it.each([ROLES.ADMINISTRADOR, ROLES.MEDICO])('%s envía solo payload permitido y preserva identidades', async rol => {
    const c = new AbortController(); input.rol = rol; input.estudio = '  Hemograma de prueba  '; input.estado = 'Disponible'; input.actor = '99';
    const d = await solicitarEstudio(input, c.signal);
    expect(d.estado).toBe('Pendiente'); expect(d.creada_por).toBe('7');
    expect(get).toHaveBeenCalledWith(csrfURL, { signal: c.signal, sessionBound: true, sessionEpochExpected: input.contexto.epocaSesion });
    expect(post).toHaveBeenCalledExactlyOnceWith('/consultas/44/solicitudes-laboratorio', { estudio_solicitado: 'Hemograma de prueba', originales: { id_version_consulta: '61' }, atencion_real_verificada: true }, { signal: c.signal, sessionBound: true, sessionEpochExpected: input.contexto.epocaSesion });
  });
  it.each(['', ' ', 'a'.repeat(256), null])('rechaza estudio inválido antes de CSRF', async estudio => {
    await expect(solicitarEstudio({ ...input, estudio })).rejects.toMatchObject({ solicitudEnviada: false }); expect(get).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled();
  });
  it('acepta hasta255 puntos de código y no agrega campos del contexto', () => {
    const payload = prepararSolicitud({ ...input, estudio: '🩺'.repeat(255) }); expect([...payload.estudio_solicitado]).toHaveLength(255); expect(payload).not.toHaveProperty('epocaSesion');
  });
  it.each([false, 1, '1', 'true', undefined])('declaración %s debe ser true estricto', async declaracion => {
    await expect(solicitarEstudio({ ...input, declaracion })).rejects.toMatchObject({ solicitudEnviada: false }); expect(post).not.toHaveBeenCalled();
  });
  it.each(['rol', 'actor', 'cita', 'version', 'accion', 'epoca'])('bloquea contexto inválido %s antes de enviar', async tipo => {
    if (tipo === 'rol') input.rol = ROLES.PACIENTE;
    if (tipo === 'actor') input.idActor = 7;
    if (tipo === 'cita') input.id = '42';
    if (tipo === 'version') input.contexto.edicion.originales.id_version_consulta = '01';
    if (tipo === 'accion') input.contexto.edicion.acciones.solicitar = false;
    if (tipo === 'epoca') avanzarSesion();
    await expect(solicitarEstudio(input)).rejects.toMatchObject({ solicitudEnviada: false }); expect(get).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled();
  });
  it('CSRF fallido es anterior al despacho y nunca reenvía', async () => {
    get.mockRejectedValue({ response: { status: 500 } }); await expect(solicitarEstudio(input)).rejects.toMatchObject({ solicitudEnviada: false }); expect(post).not.toHaveBeenCalled();
  });
  it.each(['sesion', 'abort'])('%s durante CSRF impide POST', async tipo => {
    const c = new AbortController(); get.mockImplementation(async () => { if (tipo === 'sesion') avanzarSesion(); else c.abort(); });
    await expect(solicitarEstudio(input, c.signal)).rejects.toMatchObject({ solicitudEnviada: false }); expect(post).not.toHaveBeenCalled();
  });
  it('congela originales y objetivo antes de esperar CSRF', async () => {
    get.mockImplementation(async () => { input.contexto.id_consulta = '99'; input.contexto.edicion.originales.id_version_consulta = '99'; });
    await solicitarEstudio(input); expect(post.mock.calls[0][0]).toBe('/consultas/44/solicitudes-laboratorio'); expect(post.mock.calls[0][1].originales.id_version_consulta).toBe('61');
  });
  it.each(['status', 'consulta', 'paciente', 'medico', 'actor', 'estudio', 'version'])('201 incoherente %s queda incierto, no éxito', async tipo => {
    const r = confirmado(), d = r.data.data;
    if (tipo === 'status') r.status = 200;
    if (tipo === 'consulta') d.id_consulta = '45';
    if (tipo === 'paciente') d.paciente.id_paciente = '22';
    if (tipo === 'medico') d.medico.id_medico = '32';
    if (tipo === 'actor') d.creada_por = null;
    if (tipo === 'estudio') d.estudio_solicitado = 'otro';
    if (tipo === 'version') d.edicion.vigente = { id_version_resultado: '81', numero_version: 1 };
    post.mockResolvedValue(r); await expect(solicitarEstudio(input)).rejects.toMatchObject({ solicitudEnviada: true }); expect(post).toHaveBeenCalledTimes(1);
  });
  it.each([undefined, 409, 419, 422, 429, 500])('error posterior al despacho %s no se reproduce', async status => {
    post.mockRejectedValue({ response: status ? { status } : undefined }); await expect(solicitarEstudio(input)).rejects.toMatchObject({ solicitudEnviada: true }); expect(post).toHaveBeenCalledTimes(1);
  });
});
