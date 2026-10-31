import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';
import { cancelarCita, consultarGestionCita, reprogramarCita } from '../services/gestionCita.service';

const inicial = { status: 'inicial', resultado: null, failure: null, operacion: null };
const mensajes = {
  CITA_EDICION_OBSOLETA: 'La cita cambió. Consulta la programación actual y revisa nuevamente antes de confirmar.',
  CITA_REPROGRAMACION_OBSOLETA: 'Las condiciones del turno cambiaron. Consulta la gestión y selecciona otra alternativa.',
  CITA_TURNO_NO_DISPONIBLE: 'El turno ya no está disponible. Consulta la gestión y los turnos vigentes.',
  CITA_PACIENTE_SOLAPADO: 'El Paciente tiene otra cita en ese intervalo. Consulta otro turno.',
  CITA_REPROGRAMACION_SIN_CAMBIO: 'Selecciona un inicio distinto del actual.',
  CITA_GESTION_NO_PERMITIDA: 'El estado de la cita ya no admite esta operación.',
  CITA_PLAZO_GESTION_FINALIZADO: 'El plazo para gestionar esta cita ha finalizado.',
  CITA_CON_ATENCION_DOCUMENTADA: 'La cita tiene documentación de atención y no admite este cambio.',
};

export function useGestionCita(id) {
  const [state, setState] = useState(inicial);
  const [remaining, setRemaining] = useState(0);
  const current = useRef(inicial), mounted = useRef(false), sequence = useRef(0), locked = useRef(false), until = useRef(0);
  const pending = useRef(null);
  const commit = next => { current.current = next; if (mounted.current) setState(next); };
  useEffect(() => {
    mounted.current = true;
    const hide = () => {
      sequence.current += 1; locked.current = false;
      pending.current?.abort(); pending.current = null;
      // Una escritura en vuelo puede confirmar aunque la página se oculte. Limpiar datos, no afirmar rollback.
      const pendiente = ['guardando', 'incierto', 'consultando'].includes(current.current.status);
      commit({ ...inicial, status: pendiente ? 'incierto' : 'inicial', operacion: pendiente ? current.current.operacion : null });
    };
    window.addEventListener('pagehide', hide);
    return () => { mounted.current = false; sequence.current += 1; pending.current?.abort(); pending.current = null; window.removeEventListener('pagehide', hide); };
  }, []);
  useEffect(() => {
    if (!remaining) return;
    const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000);
    return () => clearInterval(timer);
  }, [remaining]);
  const peligro = ['guardando', 'incierto', 'consultando'].includes(state.status);
  useEffect(() => {
    if (!peligro) return;
    const warn = event => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [peligro]);

  async function ejecutar(tipo, entrada, accion = 'REPROGRAMACION') {
    if (locked.current || Date.now() < until.current) return false;
    if (tipo === 'guardar' && current.current.status !== 'inicial') return false;
    if (tipo === 'consultar' && current.current.status !== 'incierto') return false;
    const prev = current.current;
    const operacion = tipo === 'guardar' ? accion : prev.operacion;
    const nombre = operacion === 'CANCELACION' ? 'cancelación' : 'reprogramación';
    const operation = ++sequence.current; locked.current = true;
    const controller = new AbortController(); pending.current = controller;
    commit({ ...prev, operacion, failure: null, status: tipo === 'guardar' ? 'guardando' : 'consultando' });
    try {
      const escribir = operacion === 'CANCELACION' ? cancelarCita : reprogramarCita;
      const resultado = await (tipo === 'guardar' ? escribir({ ...entrada, id }, controller.signal) : consultarGestionCita({ id }, controller.signal));
      if (!mounted.current || operation !== sequence.current) return false;
      commit({ ...inicial, operacion, status: tipo === 'guardar' ? 'guardada' : 'recuperada', resultado });
      return true;
    } catch (error) {
      if (!mounted.current || operation !== sequence.current) return false;
      const failure = describirError(error, { operation: 'gestion', allowedFields: operacion === 'CANCELACION'
        ? ['motivo', 'version_programacion_original', 'actualizada_en_original']
        : ['fecha', 'hora_inicio', 'revision_turno', 'version_programacion_original', 'actualizada_en_original'] });
      if (failure.status === 409) failure.message = mensajes[error.response?.data?.error?.code] ?? 'Las condiciones cambiaron. Vuelve a consultar la gestión.';
      if (failure.status === 419) failure.message = `La protección de la sesión ha caducado. No se reenviará la ${nombre} automáticamente.`;
      if (failure.status === 404) failure.message = 'La cita no está disponible.';
      if (failure.retryAfter) { until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter); }
      // Un 408/proxy o estado ajeno al contrato tampoco demuestra que no se guardó.
      const rechazoConocido = [400, 401, 403, 404, 405, 409, 419, 422, 429].includes(failure.status);
      const incierto = tipo === 'consultar' || (error.gestionEnviada === true && !rechazoConocido);
      if (incierto) failure.message = `No pudimos verificar el resultado. La ${nombre} podría haberse guardado. Consulta el estado actual antes de decidir otra operación.`;
      else if (error.gestionEnviada === false && !failure.status) failure.message = `No se envió la ${nombre}: no pudimos preparar la protección de la sesión. Vuelve a consultar antes de revisar otro envío.`;
      commit({ ...inicial, operacion, status: incierto ? 'incierto' : 'error', failure });
      return false;
    } finally { if (operation === sequence.current) { locked.current = false; pending.current = null; } }
  }
  return { ...state, remaining, peligro, guardar: entrada => ejecutar('guardar', entrada), consultar: () => ejecutar('consultar'),
    cancelar: entrada => ejecutar('guardar', entrada, 'CANCELACION'),
    reset: () => { if (!locked.current && !['incierto', 'consultando'].includes(current.current.status) && Date.now() >= until.current) commit(inicial); } };
}
