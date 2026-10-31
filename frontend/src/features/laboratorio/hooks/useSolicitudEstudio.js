import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { solicitarEstudio, consultarContextoSolicitud } from '../services/laboratorio.service';
import { laboratorioError } from '../utils/laboratorioError';

const inicial = { status: 'inicial', resultado: null, failure: null };
export function useSolicitudEstudio(id, rol, idActor) {
  const [state, setState] = useState(inicial), [remaining, setRemaining] = useState(0);
  const current = useRef(inicial), mounted = useRef(false), sequence = useRef(0), locked = useRef(false), pending = useRef(null), until = useRef(0);
  const commit = next => { current.current = next; if (mounted.current) setState(next); };
  useEffect(() => {
    mounted.current = true;
    const clear = () => {
      const riesgo = ['enviando', 'incierto', 'consultando'].includes(current.current.status);
      sequence.current++; pending.current?.abort(); locked.current = false;
      commit({ ...inicial, status: riesgo ? 'incierto' : 'error', failure: { message: 'El contexto se retiró. Vuelve a consultar antes de continuar.', fields: {} } });
    };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    return () => { mounted.current = false; sequence.current++; pending.current?.abort(); unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => {
    if (!remaining) return;
    const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000);
    return () => clearInterval(timer);
  }, [remaining]);
  async function ejecutar(entrada, recuperar = false) {
    if (!mounted.current || locked.current || Date.now() < until.current || (recuperar ? current.current.status !== 'incierto' : !['inicial', 'validacion'].includes(current.current.status))) return false;
    const operation = ++sequence.current, controller = new AbortController(); locked.current = true; pending.current = controller;
    commit({ ...inicial, status: recuperar ? 'consultando' : 'enviando' });
    try {
      const resultado = await (recuperar ? consultarContextoSolicitud({ id, rol }, controller.signal) : solicitarEstudio({ ...entrada, id, rol, idActor }, controller.signal));
      if (!mounted.current || operation !== sequence.current || controller.signal.aborted) return false;
      commit({ ...inicial, status: recuperar ? 'consultada' : 'registrada', resultado }); return true;
    } catch (error) {
      if (!mounted.current || operation !== sequence.current || controller.signal.aborted) return false;
      const failure = laboratorioError(error);
      failure.fields = {};
      if (failure.status === 422) {
        failure.message = 'Revisa los campos indicados antes de solicitar el estudio.';
        // Mensajes locales para campos conocidos; no propagar texto arbitrario del servidor.
        const details = error.response?.data?.error?.details;
        if (Array.isArray(details?.estudio_solicitado)) failure.fields.estudio_solicitado = 'Indica el estudio solicitado (hasta 255 caracteres).';
        if (Array.isArray(details?.atencion_real_verificada)) failure.fields.atencion_real_verificada = 'Declara que la atención real fue iniciada o realizada.';
      }
      if (failure.status === 409) failure.message = 'La consulta o sus condiciones cambiaron. Consulta nuevamente el contexto y revisa la solicitud.';
      if (failure.status === 419) failure.message = 'La protección de la sesión ha caducado. No se reenviará la solicitud automáticamente.';
      if (failure.retryAfter) { until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter); }
      const conocido = [400, 401, 403, 404, 405, 409, 419, 422, 429].includes(failure.status);
      const incierto = recuperar || (error.solicitudEnviada === true && !conocido);
      if (incierto) failure.message = 'No pudimos verificar el resultado. La solicitud podría haberse registrado. Consulta las solicitudes actuales antes de decidir otra operación.';
      else if (error.solicitudEnviada === false) failure.message = 'No se envió la solicitud: no pudimos preparar el envío. Consulta nuevamente el contexto antes de continuar.';
      const details = error.response?.data?.error?.details;
      const corregible = !recuperar && failure.status === 422 && Object.keys(failure.fields).length > 0
        && Object.keys(details).every(k => ['estudio_solicitado', 'atencion_real_verificada'].includes(k));
      commit({ ...inicial, status: incierto ? 'incierto' : corregible ? 'validacion' : 'error', failure }); return false;
    } finally { if (operation === sequence.current) { locked.current = false; pending.current = null; } }
  }
  return { ...state, remaining, guardar: entrada => ejecutar(entrada), consultar: () => ejecutar(null, true),
    reset: () => { if (!locked.current && !['incierto', 'consultando'].includes(current.current.status) && Date.now() >= until.current) commit(inicial); } };
}
