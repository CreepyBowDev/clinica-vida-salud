import { StrictMode } from 'react';
import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { avanzarSesion } from '../../../../api/api';
import { useDocumentoLaboratorio } from '../../hooks/useDocumentoLaboratorio';
import { guardarDocumento, consultarEstadoDocumento } from '../../services/documentoLaboratorio.service';
vi.mock('../../services/documentoLaboratorio.service', () => ({ guardarDocumento: vi.fn(), consultarEstadoDocumento: vi.fn() }));
beforeEach(() => { vi.resetAllMocks(); guardarDocumento.mockResolvedValue({ origen: {} }); consultarEstadoDocumento.mockResolvedValue({ origen: {} }); });
const mount=()=>renderHook(()=>useDocumentoLaboratorio('71'),{wrapper:StrictMode});
it('StrictMode y doble clic no duplican operación',async()=>{let resolve;guardarDocumento.mockImplementation(()=>new Promise(r=>resolve=r));const {result}=mount();let p;act(()=>{p=result.current.guardar({tipo:'cargar'});result.current.guardar({tipo:'cargar'});});expect(guardarDocumento).toHaveBeenCalledTimes(1);await act(async()=>{resolve({origen:{}});await p;});expect(result.current.status).toBe('guardada');});
it.each([undefined,500,503])('error incierto %s no admite POST o reset; recupera ambos extremos',async status=>{
  guardarDocumento.mockRejectedValue({documentoEnviado:true,response:status?{status}:undefined});const {result}=mount();
  await act(async()=>{await result.current.guardar({tipo:'reasociar',destino:{id_solicitud:'72'}});});expect(result.current.status).toBe('incierto');act(()=>result.current.reset());
  await act(async()=>{await result.current.guardar({});await result.current.consultar();});expect(guardarDocumento).toHaveBeenCalledTimes(1);expect(consultarEstadoDocumento.mock.calls[0][0]).toEqual({id:'71',destino:'72'});expect(result.current.status).toBe('consultada');
});
it('fallo GET posterior a201 conserva incertidumbre incluso con404',async()=>{guardarDocumento.mockRejectedValue({documentoEnviado:true,documentoRespuestaIncierta:true,response:{status:404}});const {result}=mount();await act(async()=>{await result.current.guardar({tipo:'cargar'});});expect(result.current.status).toBe('incierto');});
it('fallo predespacho no se presenta como alta y exige contexto nuevo',async()=>{guardarDocumento.mockRejectedValue({documentoEnviado:false});const {result}=mount();await act(async()=>{await result.current.guardar({tipo:'cargar'});});expect(result.current.status).toBe('error');expect(result.current.failure.message).toMatch(/No se envió/);});
it.each([401,403,404,409,413,419,422])('HTTP conocido%s no reenvía ni muestra texto arbitrario',async status=>{guardarDocumento.mockRejectedValue({documentoEnviado:true,response:{status,data:{error:{message:'PRIVADO'}}}});const {result}=mount();await act(async()=>{await result.current.guardar({tipo:'cargar'});});expect(result.current.status).toBe('error');expect(result.current.failure.message).not.toContain('PRIVADO');expect(guardarDocumento).toHaveBeenCalledTimes(1);});
it('422 de archivo usa mensaje local y permite revisión nueva; originales no',async()=>{
  guardarDocumento.mockRejectedValueOnce({documentoEnviado:true,response:{status:422,data:{error:{details:{archivo:['PRIVADO']}}}}}).mockRejectedValueOnce({documentoEnviado:true,response:{status:422,data:{error:{details:{archivo:['x'],'originales.ultima_version':['x']}}}}});
  const {result}=mount();await act(async()=>{await result.current.guardar({tipo:'cargar'});});expect(result.current.status).toBe('validacion');expect(result.current.failure.fields.archivo).not.toContain('PRIVADO');await act(async()=>{await result.current.guardar({tipo:'cargar'});});expect(result.current.status).toBe('error');
});
it.each(['sesion','pagehide','unmount'])('%s retira datos y aborta sin aceptar respuesta tardía',async tipo=>{
  let resolve;guardarDocumento.mockImplementation(()=>new Promise(r=>resolve=r));const {result,unmount}=mount();let p;act(()=>{p=result.current.guardar({tipo:'reasociar',destino:{id_solicitud:'72'}});});const signal=guardarDocumento.mock.calls[0][1];
  act(()=>{if(tipo==='sesion')avanzarSesion();else if(tipo==='pagehide')window.dispatchEvent(new Event('pagehide'));else unmount();});expect(signal.aborted).toBe(true);await act(async()=>{resolve({origen:{}});await p;});
  if(tipo!=='unmount'){expect(result.current.status).toBe('incierto');expect(result.current.resultado).toBeNull();await act(async()=>{await result.current.consultar();});expect(consultarEstadoDocumento.mock.calls[0][0].destino).toBe('72');}
});
it('recuperación fallida conserva bloqueo de reenvío',async()=>{guardarDocumento.mockRejectedValue({documentoEnviado:true});consultarEstadoDocumento.mockRejectedValue({response:{status:404}});const {result}=mount();await act(async()=>{await result.current.guardar({tipo:'cargar'});await result.current.consultar();});expect(result.current.status).toBe('incierto');expect(guardarDocumento).toHaveBeenCalledTimes(1);});
it('429 aplica espera a reset y nueva escritura',async()=>{guardarDocumento.mockRejectedValue({documentoEnviado:true,response:{status:429,headers:{'retry-after':'60'}}});const {result}=mount();await act(async()=>{await result.current.guardar({tipo:'cargar'});});expect(result.current.remaining).toBe(60);act(()=>result.current.reset());await act(async()=>{await result.current.guardar({tipo:'cargar'});});expect(guardarDocumento).toHaveBeenCalledTimes(1);});
