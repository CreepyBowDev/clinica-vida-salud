import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { guardarDocumento, consultarEstadoDocumento } from '../services/documentoLaboratorio.service';
import { laboratorioError } from '../utils/laboratorioError';

const inicial = { status: 'inicial', resultado: null, failure: null };
const campos = { archivo: 'Selecciona nuevamente un PDF, JPEG o PNG válido de hasta 10 MiB.', motivo_cambio: 'Indica un motivo de hasta 2000 caracteres.',
  asociacion_verificada: 'Comprueba explícitamente paciente y estudio de origen.', asociacion_destino_verificada: 'Comprueba explícitamente paciente y estudio de destino.' };
export function useDocumentoLaboratorio(id) {
  const [state, setState] = useState(inicial), [remaining, setRemaining] = useState(0);
  const current = useRef(inicial), mounted = useRef(false), sequence = useRef(0), pending = useRef(null), lock = useRef(false), until = useRef(0), destino = useRef(null);
  const commit = next => { current.current = next; if (mounted.current) setState(next); };
  useEffect(() => {
    mounted.current = true;
    const clear = () => {
      const riesgo = ['enviando', 'incierto', 'consultando'].includes(current.current.status);
      sequence.current++; pending.current?.abort(); lock.current = false;
      commit({ ...inicial, status: riesgo ? 'incierto' : 'error', failure: { message: 'El contexto se retiró. Vuelve a consultar antes de continuar.', fields: {} } });
    };
    // Solo la referencia opaca de la operación permite reconsultar ambos extremos; no conservar File ni selección clínica.
    // El componente se desmonta si cambia la identidad/rol/recurso y cada GET vuelve a autorizarse en Laravel.
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    return () => { mounted.current = false; sequence.current++; pending.current?.abort(); unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => {
    if (!remaining) return;
    const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000); return () => clearInterval(timer);
  }, [remaining]);
  async function ejecutar(entrada, recuperar = false) {
    if (!mounted.current || lock.current || until.current > Date.now() || (recuperar ? current.current.status !== 'incierto' : !['inicial', 'validacion'].includes(current.current.status))) return false;
    const operation = ++sequence.current, controller = new AbortController(); lock.current = true; pending.current = controller;
    if (!recuperar) destino.current = entrada.tipo === 'reasociar' ? entrada.destino?.id_solicitud : null;
    commit({ ...inicial, status: recuperar ? 'consultando' : 'enviando' });
    try {
      const resultado = await (recuperar ? consultarEstadoDocumento({ id, destino: destino.current }, controller.signal) : guardarDocumento(entrada, controller.signal));
      if (!mounted.current || operation !== sequence.current || controller.signal.aborted) return false;
      commit({ ...inicial, status: recuperar ? 'consultada' : 'guardada', resultado }); return true;
    } catch (error) {
      if (!mounted.current || operation !== sequence.current || controller.signal.aborted) return false;
      const failure = laboratorioError(error); failure.fields = {};
      const details = error.response?.data?.error?.details;
      if (failure.status === 422) {
        failure.message = 'Revisa los campos indicados antes de enviar el documento.';
        for (const [k, message] of Object.entries(campos)) if (Array.isArray(details?.[k])) failure.fields[k] = message;
      }
      if (failure.status === 413) failure.message = 'El archivo supera el tamaño permitido. Selecciona uno de hasta 10 MiB.';
      if (failure.status === 409) failure.message = 'La versión, el estado o la asociación cambiaron. Vuelve a consultar las solicitudes y realiza una nueva revisión.';
      if (failure.status === 419) failure.message = 'La protección de la sesión ha caducado. El documento no se reenviará automáticamente.';
      if (failure.retryAfter) { until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter); }
      const conocido = [400, 401, 403, 404, 405, 409, 413, 419, 422, 429].includes(failure.status);
      const incierto = recuperar || error.documentoRespuestaIncierta === true || (error.documentoEnviado === true && !conocido);
      if (incierto) failure.message = 'No pudimos verificar el resultado. El documento o la asociación podrían haberse guardado. Consulta el estado actual antes de decidir otro envío.';
      else if (error.documentoEnviado === false) failure.message = 'No se envió el documento: no pudimos preparar la operación. Vuelve a consultar el contexto.';
      const corregible = !incierto && failure.status === 422 && Object.keys(failure.fields).length > 0 && Object.keys(details).every(k => Object.hasOwn(campos, k));
      commit({ ...inicial, status: incierto ? 'incierto' : corregible ? 'validacion' : 'error', failure }); return false;
    } finally { if (operation === sequence.current) { lock.current = false; pending.current = null; } }
  }
  return { ...state, remaining, guardar: entrada => ejecutar(entrada), consultar: () => ejecutar(null, true), reset: () => {
    if (!lock.current && !['incierto', 'consultando'].includes(current.current.status) && until.current <= Date.now()) { destino.current = null; commit(inicial); }
  } };
}
