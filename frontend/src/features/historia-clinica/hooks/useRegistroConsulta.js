import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { describirErrorClinico } from '../utils/clinicaError';
import { registrarConsulta, actualizarConsulta, camposAlta, camposAntecedentesAlta } from '../services/consultaMedica.service';
import { consultarConsulta } from '../services/historiaClinica.service';

const inicial = { status: 'inicial', resultado: null, failure: null };
const conflictos = {
  CLINICA_EDICION_OBSOLETA: 'La cita o los antecedentes cambiaron. Consulta el contexto y realiza una nueva revisión.',
  CLINICA_CONSULTA_EXISTENTE: 'La cita ya tiene una consulta. Consulta la información vigente; no se creará otra.',
  CLINICA_CITA_NO_PERMITIDA: 'El estado actual de la cita no permite registrar la consulta.',
  CLINICA_ATENCION_ANTICIPADA: 'La atención todavía no puede registrarse. Vuelve a consultar el contexto.',
  CLINICA_LIMITE_VERSION: 'No se puede agregar otra versión clínica.',
};
export function useRegistroConsulta(id, rol, idActor) {
  const [state, setState] = useState(inicial), [remaining, setRemaining] = useState(0);
  const current = useRef(inicial), mounted = useRef(false), sequence = useRef(0), locked = useRef(false), pending = useRef(null), until = useRef(0);
  const commit = next => { current.current = next; if (mounted.current) setState(next); };
  useEffect(() => {
    mounted.current = true;
    const hide = () => {
      const riesgo = ['guardando', 'consultando', 'incierto'].includes(current.current.status);
      sequence.current += 1; pending.current?.abort(); locked.current = false;
      commit({ ...inicial, status: riesgo ? 'incierto' : 'inicial' });
    };
    window.addEventListener('pagehide', hide);
    return () => { mounted.current = false; sequence.current += 1; pending.current?.abort(); window.removeEventListener('pagehide', hide); };
  }, []);
  useEffect(() => escucharCambioSesion(() => {
    const riesgo = ['guardando', 'consultando', 'incierto'].includes(current.current.status);
    sequence.current += 1; pending.current?.abort(); locked.current = false;
    commit({ ...inicial, status: riesgo ? 'incierto' : 'error', failure: { message: 'La sesión cambió. Consulta nuevamente antes de continuar.', fields: {} } });
  }), []);
  useEffect(() => {
    if (!remaining) return;
    const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000);
    return () => clearInterval(timer);
  }, [remaining]);
  async function ejecutar(entrada, recuperar = false) {
    if (locked.current || Date.now() < until.current || (recuperar ? current.current.status !== 'incierto' : !['inicial', 'validacion'].includes(current.current.status))) return false;
    const operation = ++sequence.current, controller = new AbortController();
    locked.current = true; pending.current = controller;
    commit({ ...inicial, status: recuperar ? 'consultando' : 'guardando' });
    try {
      const escribir = entrada?.contexto?.consulta ? actualizarConsulta : registrarConsulta;
      const resultado = await (recuperar ? consultarConsulta({ id, rol }, controller.signal) : escribir({ ...entrada, id, rol, idActor }, controller.signal));
      if (!mounted.current || operation !== sequence.current) return false;
      commit({ ...inicial, status: recuperar ? 'recuperada' : 'guardada', resultado }); return true;
    } catch (error) {
      if (!mounted.current || operation !== sequence.current) return false;
      const allowedFields = [...Object.keys(camposAlta).map(k => `consulta.${k}`), ...Object.keys(camposAntecedentesAlta).map(k => `antecedentes.${k}`), 'atencion_real_verificada', 'motivo_cambio'];
      const failure = describirErrorClinico(error, allowedFields);
      if (failure.status === 409) failure.message = conflictos[error.response?.data?.error?.code] ?? 'Las condiciones cambiaron. Consulta el contexto antes de continuar.';
      if (failure.status === 419) failure.message = 'La protección de la sesión ha caducado. No se reenviará la consulta automáticamente.';
      if (failure.status === 404) failure.message = 'La cita no está disponible o no tienes acceso a ella.';
      if (failure.retryAfter) { until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter); }
      const conocido = [400, 401, 403, 404, 405, 409, 419, 422, 429].includes(failure.status);
      const incierto = recuperar || (error.consultaEnviada === true && !conocido);
      if (incierto) failure.message = 'No pudimos verificar el resultado. La consulta podría haberse guardado. Consulta el estado actual antes de decidir otra operación.';
      else if (error.consultaEnviada === false) failure.message = 'No se envió la consulta: no pudimos preparar el registro. Consulta nuevamente el contexto antes de continuar.';
      const corregible = !recuperar && failure.status === 422 && Object.keys(failure.fields).length > 0;
      commit({ ...inicial, status: incierto ? 'incierto' : corregible ? 'validacion' : 'error', failure }); return false;
    } finally { if (operation === sequence.current) { locked.current = false; pending.current = null; } }
  }
  return { ...state, remaining, guardar: entrada => ejecutar(entrada), consultar: () => ejecutar(null, true),
    reset: () => { if (!locked.current && !['incierto', 'consultando'].includes(current.current.status) && Date.now() >= until.current) commit(inicial); } };
}
