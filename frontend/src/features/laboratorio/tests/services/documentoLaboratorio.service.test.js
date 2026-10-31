import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, csrfURL, obtenerEpocaSesion, avanzarSesion } from '../../../../api/api';
import { ROLES } from '../../../../constants/roles';
import { consultarSolicitud, consultarVersionesResultado, proyectarSolicitud, MAX_RESULTADO } from '../../services/laboratorio.service';
import { guardarDocumento, prepararDocumento, validarArchivoLaboratorio, consultarEstadoDocumento } from '../../services/documentoLaboratorio.service';
import { solicitud, disponible, documentada, listado, instante } from '../utils/laboratorioFixtures';
const A = ROLES.ADMINISTRADOR;
const ctx = raw => ({ ...proyectarSolicitud(raw, A, true, true), epocaSesion: obtenerEpocaSesion() });
const file = (name = 'ficticio.pdf', type = 'application/pdf', bytes = 15) => new File([new Uint8Array(bytes)], name, { type });
const entrada = () => ({ rol: A, contexto: ctx(solicitud()), tipo: 'cargar', archivo: file(), asociacion: true });
let get, post;
beforeEach(() => { get = vi.spyOn(api, 'get').mockResolvedValue({ status: 204 }); post = vi.spyOn(api, 'post').mockResolvedValue({ status: 201, data: { data: documentada() } }); });
afterEach(() => vi.restoreAllMocks());
describe('originales y metadatos autorizados', () => {
  it('Admin opta por originales y acciones documentales, nunca revisión futura', async () => {
    get.mockResolvedValue({ status: 200, data: { data: solicitud() } });
    const d = await consultarSolicitud({ id: '71', rol: A, paraDocumentar: true });
    expect(d.edicion).toEqual({ originales: solicitud().edicion.originales, acciones: { cargar: true, corregir: false, reasociar: false } }); expect(d.epocaSesion).toBe(obtenerEpocaSesion());
    const normal = await consultarSolicitud({ id: '71', rol: A }); expect(normal).not.toHaveProperty('edicion'); expect(normal).not.toHaveProperty('epocaSesion');
  });
  it.each([ROLES.MEDICO, ROLES.PACIENTE, ROLES.RECEPCIONISTA])('%s no obtiene originales administrativos', async rol => {
    await expect(consultarSolicitud({ id: '71', rol, paraDocumentar: true })).rejects.toThrow(); expect(get).not.toHaveBeenCalled();
  });
  it.each(['estado', 'vigente', 'revision', 'ultima', 'accion', 'publicado'])('rechaza contexto documental incoherente %s', caso => {
    const d = caso === 'publicado' ? disponible() : solicitud(), o = d.edicion.originales;
    if (caso === 'estado') o.estado_interno = 'Disponible';
    if (caso === 'vigente') o.id_version_actual = '81';
    if (caso === 'revision') o.id_ultima_revision = '91';
    if (caso === 'ultima') o.ultima_version = -1;
    if (caso === 'accion') d.edicion.acciones.corregir = true;
    if (caso === 'publicado') d.edicion.acciones.cargar = true;
    expect(() => proyectarSolicitud(d, A, true, true)).toThrow();
  });
  it('versiones Médico descartan origen y respetan motivo no compartido', async () => {
    get.mockResolvedValue({ status: 200, data: listado([{ id_version_resultado: '81', numero_version: 1, id_autor: '9', registrada_en: instante, id_version_origen: '999', secreto: 'PRIVADO' }]) });
    const d = await consultarVersionesResultado({ id: '71', rol: ROLES.MEDICO }); expect(d.versiones[0]).not.toHaveProperty('id_version_origen'); expect(d.versiones[0]).not.toHaveProperty('motivo_cambio'); expect(JSON.stringify(d)).not.toContain('PRIVADO');
    expect(get.mock.calls[0][1].params).toEqual({ page: 1, per_page: 20 });
  });
  it('versiones Admin muestran referencia sin expandir origen', async () => {
    get.mockResolvedValue({ status: 200, data: listado([{ id_version_resultado: '81', numero_version: 1, id_autor: '9', registrada_en: instante, id_version_origen: '999', motivo_cambio: 'Corrección ficticia' }]) });
    const d = await consultarVersionesResultado({ id: '71', rol: A }); expect(d.versiones[0].id_version_origen).toBe('999'); expect(get).toHaveBeenCalledTimes(1);
  });
  it.each([ROLES.PACIENTE, ROLES.RECEPCIONISTA])('%s no consulta historial', async rol => { await expect(consultarVersionesResultado({ id: '71', rol })).rejects.toThrow(); expect(get).not.toHaveBeenCalled(); });
  it('historial valida orden descendente y paginación', async () => {
    get.mockResolvedValue({ status: 200, data: listado([1, 2].map(n => ({ id_version_resultado: String(n), numero_version: n, id_autor: '9', registrada_en: instante }))) });
    await expect(consultarVersionesResultado({ id: '71', rol: ROLES.MEDICO })).rejects.toThrow();
  });
});
describe('archivo y preparación de payload', () => {
  it.each([['pdf','application/pdf'],['jpg','image/jpeg'],['jpeg','image/jpeg'],['png','image/png']])('acepta %s, sin validación clínica ficticia', (ext, mime) => { expect(validarArchivoLaboratorio(file(`ficticio.${ext}`, mime))).toBeNull(); });
  it.each([['x.svg','image/svg+xml',15],['x.pdf','text/html',15],['x.pdf','application/pdf',0],['x.pdf','application/pdf',MAX_RESULTADO+1]])('rechaza %s %s %s', (n,t,s) => { expect(validarArchivoLaboratorio(file(n,t,s))).not.toBeNull(); });
  it('acepta MIME vacío como ayuda de selección y exactamente10MiB; backend valida bytes', () => { expect(validarArchivoLaboratorio(file('x.PDF','',MAX_RESULTADO))).toBeNull(); });
  it('multipart usa flags1 y null vacío, no true ni boundary manual', () => {
    const d = prepararDocumento(entrada()); expect(d).toBeInstanceOf(FormData); expect(d.get('asociacion_verificada')).toBe('1'); expect(d.get('originales[id_version_actual]')).toBe(''); expect(d.get('originales[ultima_version]')).toBe('0'); expect(d.get('tipo')).toBeNull(); expect(d.get('motivo_cambio')).toBeNull();
  });
  it('sustituir requiere devolución, motivo y archivo; recarga de origen con historia exige motivo', () => {
    const e = { ...entrada(), contexto: ctx(documentada({devuelta:true})), tipo: 'sustituir', motivo: '  Corrección ficticia  ' };
    expect(prepararDocumento(e).get('motivo_cambio')).toBe('Corrección ficticia');
    expect(() => prepararDocumento({...e,motivo:''})).toThrow(); expect(() => prepararDocumento({...e,contexto:ctx(documentada())})).toThrow();
    const raw = solicitud({estado:'En revisión'}); raw.edicion.originales.estado_interno='En revisión'; raw.edicion.originales.ultima_version=2;
    expect(() => prepararDocumento({...entrada(),contexto:ctx(raw)})).toThrow();
    expect(prepararDocumento({...entrada(),contexto:ctx(raw),motivo:'Recarga ficticia'}).get('motivo_cambio')).toBe('Recarga ficticia');
  });
  it('reasociar produce JSON sin File, originales y ambas confirmaciones1', () => {
    const e = reasociacion(), d = prepararDocumento(e); expect(d.tipo).toBe('reasociar'); expect(d.asociacion_verificada).toBe(1); expect(d.asociacion_destino_verificada).toBe(1); expect(d).not.toHaveProperty('archivo'); expect(d.originales_destino.ultima_version).toBe(0);
  });
  it.each(['mismo','publicado','historia','declaracion','archivo','epoca'])('bloquea reasociación inválida %s', caso => {
    const e = reasociacion();
    if(caso==='mismo')e.destino.id_solicitud='71'; if(caso==='publicado')e.destino.estado='Disponible'; if(caso==='historia')e.destino.edicion.originales.ultima_version=1;
    if(caso==='declaracion')e.asociacionDestino=false;if(caso==='archivo')e.archivo=file();if(caso==='epoca')e.destino.epocaSesion--;
    expect(()=>prepararDocumento(e)).toThrow();
  });
});
function reasociacion(){return{rol:A,contexto:ctx(documentada({devuelta:true})),tipo:'reasociar',destino:ctx(solicitud({id_solicitud:'72',paciente:{...solicitud().paciente,id_paciente:'22'}})),asociacion:true,asociacionDestino:true,motivo:'Asociación ficticia corregida'};}
describe('envío documental y recuperación', () => {
  it('carga con CSRF y sesión ligados; valida201 sin publicar', async () => {
    const d = await guardarDocumento(entrada()); expect(d.origen.estado).toBe('En revisión'); expect(get.mock.calls[0][0]).toBe(csrfURL);
    expect(post).toHaveBeenCalledWith('/solicitudes-laboratorio/71/documentos',expect.any(FormData),expect.objectContaining({sessionBound:true,sessionEpochExpected:obtenerEpocaSesion()})); expect(post.mock.calls[0][2]).not.toHaveProperty('headers');
  });
  it('sustituye y verifica numeración nueva',async()=>{
    post.mockResolvedValue({status:201,data:{data:documentada({numero:2,version:'82'})}});
    const d=await guardarDocumento({...entrada(),contexto:ctx(documentada({devuelta:true})),tipo:'sustituir',motivo:'Copia legible ficticia'});expect(d.origen.documento.numero_version).toBe(2);expect(post.mock.calls[0][0]).toContain('/correcciones');
  });
  it('reasociación comprueba ambos extremos y GET protegido del destino', async () => {
    const e=reasociacion(), origen=documentada({devuelta:true}); origen.edicion.vigente=null; origen.edicion.ultima_revision=null; origen.edicion.originales.id_version_actual=null; origen.edicion.originales.id_ultima_revision=null; origen.edicion.acciones={cargar:true,corregir:false,reasociar:false};
    const target=documentada({id_solicitud:'72',paciente:e.destino.paciente,version:'82'});post.mockResolvedValue({status:201,data:{data:{...origen,destino:target}}});
    get.mockImplementation(async path=>path===csrfURL?{status:204}:{status:200,data:{data:target}});
    const d=await guardarDocumento(e);expect(d.origen.documento).toBeNull();expect(d.destino.documento.id_version_resultado).toBe('82');expect(post.mock.calls[0][1]).not.toBeInstanceOf(FormData);expect(get.mock.calls[1][0]).toBe('/solicitudes-laboratorio/72');
  });
  it.each([ROLES.MEDICO,ROLES.PACIENTE,ROLES.RECEPCIONISTA])('%s no despacha documentos', async rol=>{await expect(guardarDocumento({...entrada(),rol})).rejects.toMatchObject({documentoEnviado:false});expect(post).not.toHaveBeenCalled();expect(get).not.toHaveBeenCalled();});
  it.each(['csrf','sesion','abort'])('%s antes del despacho no escribe',async caso=>{
    const c=new AbortController(),e=entrada();get.mockImplementation(async()=>{if(caso==='csrf')throw Error('fallo');if(caso==='sesion')avanzarSesion();else c.abort();});
    await expect(guardarDocumento(e,c.signal)).rejects.toMatchObject({documentoEnviado:false});expect(post).not.toHaveBeenCalled();
  });
  it('congela identidad y originales antes de CSRF',async()=>{const e=entrada();get.mockImplementation(async()=>{e.contexto.id_solicitud='999';e.contexto.edicion.originales.ultima_version=99;});await guardarDocumento(e);expect(post.mock.calls[0][0]).toBe('/solicitudes-laboratorio/71/documentos');expect(post.mock.calls[0][1].get('originales[ultima_version]')).toBe('0');});
  it.each(['estado','numero','paciente','status'])('respuesta201 incoherente %s queda incierta',async caso=>{
    const r={status:201,data:{data:documentada()}};if(caso==='estado')r.data.data=disponible();if(caso==='numero')r.data.data=documentada({numero:2});if(caso==='paciente')r.data.data.paciente.id_paciente='22';if(caso==='status')r.status=200;
    post.mockResolvedValue(r);await expect(guardarDocumento(entrada())).rejects.toMatchObject({documentoEnviado:true,documentoRespuestaIncierta:true});expect(post).toHaveBeenCalledTimes(1);
  });
  it.each([undefined,409,413,419,422,429,500,503])('HTTP %s tras despacho no se reenvía',async status=>{post.mockRejectedValue({response:status?{status}:undefined});await expect(guardarDocumento(entrada())).rejects.toMatchObject({documentoEnviado:true,documentoRespuestaIncierta:false});expect(post).toHaveBeenCalledTimes(1);});
  it('recupera ambos recursos solo mediante GET',async()=>{get.mockImplementation(async path=>({status:200,data:{data:solicitud({id_solicitud:path.includes('/72')?'72':'71'})}}));const d=await consultarEstadoDocumento({id:'71',destino:'72'});expect(d.destino.id_solicitud).toBe('72');expect(post).not.toHaveBeenCalled();expect(get).toHaveBeenCalledTimes(2);});
  it('GET fallido del destino después de201 no se convierte en rechazo seguro del POST',async()=>{
    const e=reasociacion(),raw=documentada({devuelta:true});raw.edicion.vigente=null;raw.edicion.ultima_revision=null;raw.edicion.originales.id_version_actual=null;raw.edicion.originales.id_ultima_revision=null;raw.edicion.acciones={cargar:true,corregir:false,reasociar:false};const target=documentada({id_solicitud:'72',paciente:e.destino.paciente,version:'82'});
    post.mockResolvedValue({status:201,data:{data:{...raw,destino:target}}});get.mockImplementation(async path=>{if(path===csrfURL)return{status:204};throw{response:{status:404}};});
    await expect(guardarDocumento(e)).rejects.toMatchObject({documentoEnviado:true,documentoRespuestaIncierta:true,response:{status:404}});expect(post).toHaveBeenCalledTimes(1);
  });
});
