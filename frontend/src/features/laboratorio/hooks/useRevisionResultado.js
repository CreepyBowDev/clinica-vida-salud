import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { revisarResultado, consultarEstadoRevision } from '../services/revisionResultado.service';
import { laboratorioError } from '../utils/laboratorioError';

const inicial = { status: 'inicial', resultado: null, failure: null };
const campos = { decision: 'Selecciona Autorizar publicación o Devolver para corrección.', motivo: 'Indica el motivo de devolución (hasta 2000 caracteres).', correspondencia_verificada: 'Comprueba legibilidad, paciente y estudio antes de continuar.' };
export function useRevisionResultado(id, rol, idActor) {
  const [state, setState] = useState(inicial), [remaining, setRemaining] = useState(0);
  const current = useRef(inicial), mounted = useRef(false), sequence = useRef(0), pending = useRef(null), lock = useRef(false), until = useRef(0);
  const commit = next => { current.current = next; if (mounted.current) setState(next); };
  useEffect(() => {
    mounted.current = true;
    const clear = () => {
      const riesgo = ['enviando', 'incierto', 'consultando'].includes(current.current.status);
      sequence.current++; pending.current?.abort(); lock.current = false;
      commit({ ...inicial, status: riesgo ? 'incierto' : 'error', failure: { message: 'El contexto se retiró. Vuelve a consultar antes de continuar.', fields: {} } });
    };
    const unsubscribe = escucharCambioSesion(clear); window.addEventListener('pagehide', clear);
    return () => { mounted.current = false; sequence.current++; pending.current?.abort(); unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => {
    if (!remaining) return;
    const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000); return () => clearInterval(timer);
  }, [remaining]);
  async function ejecutar(entrada, recuperar = false) {
    if (!mounted.current || lock.current || until.current > Date.now() || (recuperar ? current.current.status !== 'incierto' : !['inicial', 'validacion'].includes(current.current.status))) return false;
    const operation = ++sequence.current, controller = new AbortController(); lock.current = true; pending.current = controller;
    commit({ ...inicial, status: recuperar ? 'consultando' : 'enviando' });
    try {
      const resultado = await (recuperar ? consultarEstadoRevision({ id, rol }, controller.signal) : revisarResultado({ ...entrada, id, rol, idActor }, controller.signal));
      if (!mounted.current || operation !== sequence.current || controller.signal.aborted) return false;
      commit({ ...inicial, status: recuperar ? 'consultada' : 'guardada', resultado }); return true;
    } catch (error) {
      if (!mounted.current || operation !== sequence.current || controller.signal.aborted) return false;
      const failure = laboratorioError(error), details = error.response?.data?.error?.details; failure.fields = {};
      if (failure.status === 422) {
        failure.message = 'Revisa los campos indicados antes de confirmar la revisión.';
        for (const [k, message] of Object.entries(campos)) if (Array.isArray(details?.[k])) failure.fields[k] = message;
      }
      if (failure.status === 409) failure.message = 'El documento, la versión o el estado cambiaron. Consulta la solicitud y realiza una nueva revisión.';
      if (failure.status === 419) failure.message = 'La protección de la sesión ha caducado. La revisión no se reenviará automáticamente.';
      if (failure.retryAfter) { until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter); }
      const conocido = [400, 401, 403, 404, 405, 409, 419, 422, 429].includes(failure.status);
      const incierto = recuperar || (error.revisionEnviada === true && !conocido);
      if (incierto) failure.message = 'No pudimos verificar el resultado. La revisión podría haberse guardado. Consulta el estado actual antes de decidir otra operación.';
      else if (error.revisionEnviada === false) failure.message = 'No se envió la revisión: no pudimos preparar la operación. Vuelve a consultar el contexto.';
      const corregible = !incierto && failure.status === 422 && Object.keys(failure.fields).length > 0 && Object.keys(details).every(k => Object.hasOwn(campos, k));
      commit({ ...inicial, status: incierto ? 'incierto' : corregible ? 'validacion' : 'error', failure }); return false;
    } finally { if (operation === sequence.current) { lock.current = false; pending.current = null; } }
  }
  return { ...state, remaining, guardar: entrada => ejecutar(entrada), consultar: () => ejecutar(null, true), reset: () => {
    if (!lock.current && !['incierto', 'consultando'].includes(current.current.status) && until.current <= Date.now()) commit(inicial);
  } };
}
