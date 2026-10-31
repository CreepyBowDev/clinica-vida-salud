import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';
import { confirmarLlegada, consultarAsistenciaCita, registrarInasistencia, marcarAtendida } from '../services/asistenciaCita.service';

const inicial = { status: 'inicial', resultado: null, failure: null, operacion: null };
const conflictos = {
  CITA_EDICION_OBSOLETA: 'La cita cambió. Consulta la asistencia y realiza una nueva revisión.',
  CITA_TRANSICION_NO_PERMITIDA: 'El estado actual no permite esta operación de asistencia.',
  CITA_CON_ATENCION_DOCUMENTADA: 'La cita tiene documentación de atención asociada y no admite este cambio de asistencia.',
  CITA_LLEGADA_FUERA_DE_PLAZO: 'La llegada requiere una cita del mismo día local cuyo intervalo no haya terminado.',
  CITA_INASISTENCIA_ANTICIPADA: 'El intervalo de la cita todavía no ha finalizado. Consulta nuevamente antes de registrar ausencia.',
  CITA_ATENCION_ANTICIPADA: 'La cita todavía no ha alcanzado su inicio. Consulta nuevamente antes de registrar atención.',
};

export function useAsistenciaCita(id, rol, idActor) {
  const [state, setState] = useState(inicial), [remaining, setRemaining] = useState(0);
  const current = useRef(inicial), mounted = useRef(false), sequence = useRef(0), locked = useRef(false), until = useRef(0), pending = useRef(null);
  const commit = next => { current.current = next; if (mounted.current) setState(next); };
  useEffect(() => {
    mounted.current = true;
    const hide = () => {
      sequence.current += 1; locked.current = false; pending.current?.abort(); pending.current = null;
      const riesgo = ['guardando', 'consultando', 'incierto'].includes(current.current.status);
      commit({ ...inicial, status: riesgo ? 'incierto' : 'inicial', operacion: riesgo ? current.current.operacion : null });
    };
    window.addEventListener('pagehide', hide);
    return () => { mounted.current = false; sequence.current += 1; pending.current?.abort(); window.removeEventListener('pagehide', hide); };
  }, []);
  useEffect(() => {
    if (!remaining) return;
    const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000);
    return () => clearInterval(timer);
  }, [remaining]);
  const peligro = ['guardando', 'consultando', 'incierto'].includes(state.status);
  useEffect(() => {
    if (!peligro) return;
    const warn = event => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [peligro]);
  async function ejecutar(tipo, entrada, accion = 'LLEGADA') {
    if (locked.current || Date.now() < until.current || (tipo === 'guardar' ? current.current.status !== 'inicial' : current.current.status !== 'incierto')) return false;
    const operation = ++sequence.current, controller = new AbortController();
    const operacion = tipo === 'guardar' ? accion : current.current.operacion;
    const ausencia = operacion === 'INASISTENCIA';
    const atencion = operacion === 'ATENCION';
    const nombre = atencion ? 'atención' : ausencia ? 'inasistencia' : 'llegada';
    locked.current = true; pending.current = controller;
    commit({ ...inicial, operacion, status: tipo === 'guardar' ? 'guardando' : 'consultando' });
    try {
      const escribir = atencion ? marcarAtendida : ausencia ? registrarInasistencia : confirmarLlegada;
      const resultado = await (tipo === 'guardar' ? escribir({ ...entrada, id, rol, idActor }, controller.signal)
        : consultarAsistenciaCita({ id, rol }, controller.signal));
      if (!mounted.current || operation !== sequence.current) return false;
      commit({ ...inicial, operacion, status: tipo === 'guardar' ? 'guardada' : 'recuperada', resultado });
      return true;
    } catch (error) {
      if (!mounted.current || operation !== sequence.current) return false;
      const failure = describirError(error, { operation: 'asistencia', allowedFields: [atencion ? 'atencion_realizada' : ausencia ? 'ausencia_verificada' : 'presencia_verificada', 'version_programacion_original', 'actualizada_en_original'] });
      if (failure.status === 409) failure.message = conflictos[error.response?.data?.error?.code] ?? 'Las condiciones cambiaron. Consulta nuevamente la asistencia.';
      if (failure.status === 419) failure.message = `La protección de la sesión ha caducado. No se reenviará la ${nombre} automáticamente.`;
      if (failure.status === 404) failure.message = 'La cita no está disponible.';
      if (failure.retryAfter) { until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter); }
      const conocido = [400, 401, 403, 404, 405, 409, 419, 422, 429].includes(failure.status);
      const incierto = tipo === 'consultar' || (error.asistenciaEnviada === true && !conocido);
      if (incierto) failure.message = `No pudimos verificar el resultado. La ${nombre} podría haberse guardado. Consulta el estado actual antes de otra operación.`;
      else if (error.asistenciaEnviada === false) failure.message = `No se envió la ${nombre}: no pudimos preparar la protección de la sesión. Consulta nuevamente antes de revisar otro envío.`;
      commit({ ...inicial, operacion, status: incierto ? 'incierto' : 'error', failure });
      return false;
    } finally { if (operation === sequence.current) { locked.current = false; pending.current = null; } }
  }
  return { ...state, remaining, peligro, guardar: entrada => ejecutar('guardar', entrada), consultar: () => ejecutar('consultar'),
    registrarInasistencia: entrada => ejecutar('guardar', entrada, 'INASISTENCIA'),
    marcarAtendida: entrada => ejecutar('guardar', entrada, 'ATENCION'),
    reset: () => { if (!locked.current && !['incierto', 'consultando'].includes(current.current.status) && Date.now() >= until.current) commit(inicial); } };
}
