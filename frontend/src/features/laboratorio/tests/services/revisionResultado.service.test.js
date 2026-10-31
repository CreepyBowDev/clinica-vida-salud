import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL, obtenerEpocaSesion, avanzarSesion } from '../../../../api/api';
import { ROLES } from '../../../../constants/roles';
import { consultarSolicitud, proyectarSolicitud } from '../../services/laboratorio.service';
import { prepararRevision, revisarResultado, consultarEstadoRevision } from '../../services/revisionResultado.service';
import { solicitud, documentada, disponible } from '../utils/laboratorioFixtures';

const A=ROLES.ADMINISTRADOR,M=ROLES.MEDICO;
const contexto=(raw=documentada(),rol=M)=>({...proyectarSolicitud(raw,rol,true,false,true),epocaSesion:obtenerEpocaSesion()});
const entrada=()=>({id:'71',rol:M,idActor:'7',contexto:contexto(),decision:'Autorizar',declaracion:true});
function confirmada(decision='Autorizar',motivo='Documento ficticio ilegible'){
  const raw=decision==='Autorizar'?disponible():documentada({devuelta:true});raw.edicion.ultima_revision.revisada_por='7';if(decision==='Devolver')raw.edicion.ultima_revision.motivo=motivo;
  return{status:201,data:{data:raw}};
}
let get,post;
beforeEach(()=>{get=vi.spyOn(api,'get').mockResolvedValue({status:204});post=vi.spyOn(api,'post').mockResolvedValue(confirmada());});
afterEach(()=>vi.restoreAllMocks());
it.each([A,M])('%s proyecta solo originales/acción de revisión cuando lo solicita explícitamente',async rol=>{
  get.mockResolvedValue({status:200,data:{data:documentada()}});const d=await consultarSolicitud({id:'71',rol,paraRevisar:true});expect(d.revision.acciones).toEqual({revisar:true});expect(d.revision.originales.id_version_actual).toBe('81');expect(d).not.toHaveProperty('edicion');
  const read=await consultarSolicitud({id:'71',rol});expect(read).not.toHaveProperty('revision');expect(read).not.toHaveProperty('epocaSesion');expect(get.mock.calls[0][1]).not.toHaveProperty('paraRevisar');
});
it('Admin puede obtener ambos contextos sin compartir objetos mutables ni concederlos al Médico',async()=>{get.mockResolvedValue({status:200,data:{data:documentada()}});const d=await consultarSolicitud({id:'71',rol:A,paraDocumentar:true,paraRevisar:true});expect(d.edicion.acciones).toEqual({cargar:false,corregir:false,reasociar:false});expect(d.revision.originales).not.toBe(d.edicion.originales);});
it.each([ROLES.PACIENTE,ROLES.RECEPCIONISTA])('%s no recibe contexto de revisión ni despacha POST',async rol=>{await expect(consultarSolicitud({id:'71',rol,paraRevisar:true})).rejects.toThrow();await expect(revisarResultado({...entrada(),rol})).rejects.toMatchObject({revisionEnviada:false});expect(get).not.toHaveBeenCalled();expect(post).not.toHaveBeenCalled();});
it.each(['pendiente','devuelta','publicada','vacia'])('%s no permite revisar otra vez',async caso=>{
  const raw=caso==='publicada'?disponible():caso==='devuelta'?documentada({devuelta:true}):solicitud();if(caso==='vacia'){raw.estado='En revisión';raw.edicion.originales.estado_interno='En revisión';raw.edicion.originales.ultima_version=2;}
  await expect(revisarResultado({...entrada(),contexto:contexto(raw)})).rejects.toMatchObject({revisionEnviada:false});expect(post).not.toHaveBeenCalled();
});
it('acción de revisar incoherente con versión devuelta se rechaza',()=>{const raw=documentada({devuelta:true});raw.edicion.acciones.revisar=true;expect(()=>contexto(raw)).toThrow();});
it.each([false,1,'1','true',undefined])('declaración%s no equivale a true estricto',async declaracion=>{await expect(revisarResultado({...entrada(),declaracion})).rejects.toMatchObject({revisionEnviada:false});expect(get).not.toHaveBeenCalled();});
it.each(['','Publicar','Disponible',null])('decisión%s no se inventa ni preselecciona',async decision=>{await expect(revisarResultado({...entrada(),decision})).rejects.toMatchObject({revisionEnviada:false});expect(post).not.toHaveBeenCalled();});
it.each(['',' ','a'.repeat(2001),null])('motivo inválido%s para Devolver se rechaza',async motivo=>{await expect(revisarResultado({...entrada(),decision:'Devolver',motivo})).rejects.toMatchObject({revisionEnviada:false});expect(post).not.toHaveBeenCalled();});
it('Devolver admite2000 puntos de código y Autorizar siempre omite motivo',()=>{
  const e=entrada();expect([...prepararRevision({...e,decision:'Devolver',motivo:'🩺'.repeat(2000)}).motivo]).toHaveLength(2000);
  expect(prepararRevision({...e,motivo:'No enviar'})).toEqual({originales:{estado_interno:'En revisión',id_version_actual:'81',id_ultima_revision:null,ultima_version:1},decision:'Autorizar',correspondencia_verificada:true});
});
it.each([A,M])('%s autoriza versión exacta con CSRF, JSON y autoría separada del responsable',async rol=>{
  const d=await revisarResultado({...entrada(),rol,motivo:'No enviar',estado:'Disponible',actor:'99'});expect(d.estado).toBe('Disponible');expect(d.ultima_revision.revisada_por).toBe('7');expect(d.ultima_revision.id_medico_responsable).toBe('31');
  expect(get).toHaveBeenCalledWith(csrfURL,expect.objectContaining({sessionBound:true,sessionEpochExpected:obtenerEpocaSesion()}));expect(post).toHaveBeenCalledWith('/solicitudes-laboratorio/71/revisiones',{originales:{estado_interno:'En revisión',id_version_actual:'81',id_ultima_revision:null,ultima_version:1},decision:'Autorizar',correspondencia_verificada:true},expect.objectContaining({sessionBound:true,sessionEpochExpected:obtenerEpocaSesion()}));
});
it('devuelve con motivo recortado sin publicar ni modificar la versión',async()=>{post.mockResolvedValue(confirmada('Devolver','Corrección ficticia'));const d=await revisarResultado({...entrada(),decision:'Devolver',motivo:'  Corrección ficticia  '});expect(d.estado).toBe('En revisión');expect(d.resultado).toBeNull();expect(d.documento.id_version_resultado).toBe('81');expect(post.mock.calls[0][1].motivo).toBe('Corrección ficticia');});
it.each(['id','actor','consulta','version','numero','epoca'])('contexto inválido%s no inicia CSRF',async caso=>{const e=entrada();if(caso==='id')e.id='72';if(caso==='actor')e.idActor='01';if(caso==='consulta')e.contexto.id_consulta='01';if(caso==='version')e.contexto.revision.originales.id_version_actual='82';if(caso==='numero')e.contexto.revision.originales.ultima_version=2;if(caso==='epoca')avanzarSesion();await expect(revisarResultado(e)).rejects.toMatchObject({revisionEnviada:false});expect(get).not.toHaveBeenCalled();});
it.each(['csrf','sesion','abort'])('%s durante preparación no envía la revisión',async caso=>{const e=entrada(),c=new AbortController();get.mockImplementation(async()=>{if(caso==='csrf')throw Error('PRIVADO');if(caso==='sesion')avanzarSesion();else c.abort();});await expect(revisarResultado(e,c.signal)).rejects.toMatchObject({revisionEnviada:false});expect(post).not.toHaveBeenCalled();});
it('originales e identidad se capturan antes de esperar CSRF',async()=>{const e=entrada();get.mockImplementation(async()=>{e.contexto.paciente.id_paciente='99';e.contexto.revision.originales.id_version_actual='99';});await revisarResultado(e);expect(post.mock.calls[0][1].originales.id_version_actual).toBe('81');});
it.each(['estado','version','actor','responsable','motivo','decision','paciente','status','accion'])('201 incoherente%s no declara éxito',async caso=>{
  const r=confirmada();if(caso==='estado')r.data.data.estado='En revisión';if(caso==='version'){r.data.data.edicion.vigente.id_version_resultado='82';r.data.data.edicion.originales.id_version_actual='82';r.data.data.resultado.id_version_resultado='82';}
  if(caso==='actor')r.data.data.edicion.ultima_revision.revisada_por='8';if(caso==='responsable')r.data.data.edicion.ultima_revision.id_medico_responsable='32';if(caso==='motivo')r.data.data.edicion.ultima_revision.motivo='No autorizado';if(caso==='decision')r.data.data.edicion.ultima_revision.decision='Devolver';if(caso==='paciente')r.data.data.paciente.id_paciente='22';if(caso==='status')r.status=200;if(caso==='accion')r.data.data.edicion.acciones.revisar=true;
  post.mockResolvedValue(r);await expect(revisarResultado(entrada())).rejects.toMatchObject({revisionEnviada:true});expect(post).toHaveBeenCalledTimes(1);
});
it.each([undefined,401,403,404,409,419,422,429,500])('HTTP%s posterior no se reenvía',async status=>{post.mockRejectedValue({response:status?{status}:undefined});await expect(revisarResultado(entrada())).rejects.toMatchObject({revisionEnviada:true});expect(post).toHaveBeenCalledTimes(1);});
it('GET de recuperación no escribe y devuelve solo el estado presente',async()=>{get.mockResolvedValue({status:200,data:{data:disponible()}});const d=await consultarEstadoRevision({id:'71',rol:M});expect(d.estado).toBe('Disponible');expect(post).not.toHaveBeenCalled();expect(get).toHaveBeenCalledTimes(1);});
