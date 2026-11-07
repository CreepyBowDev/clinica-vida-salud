import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';
import { proponerReserva, confirmarReserva, consultarResultadoReserva } from '../services/reserva.service';

const initial = { status: 'busqueda', propuesta: null, cita: null, failure: null };
const mensajes = {
  CITA_PROPUESTA_OBSOLETA: 'Las condiciones revisadas cambiaron. Consulta los turnos y revisa una nueva propuesta.',
  CITA_TURNO_NO_DISPONIBLE: 'El turno ya no está disponible. Consulta otros turnos.',
  CITA_PACIENTE_SOLAPADO: 'El Paciente ya tiene una cita en ese intervalo. Consulta otro turno.',
  CITA_PACIENTE_NO_DISPONIBLE: 'La ficha del Paciente no permite nuevas reservas.',
};
export function useReservaRegistro() {
  const [state, setState] = useState(initial);
  const [remaining, setRemaining] = useState(0);
  const mounted = useRef(false), sequence = useRef(0), locked = useRef(false), until = useRef(0);
  const current = useRef(initial);
  const pending = useRef(null), recovery = useRef(null);
  function commit(next) { current.current = next; if (mounted.current) setState(next); }
  function reset() {
    if (locked.current || ['incierto', 'consultando'].includes(current.current.status) || Date.now() < until.current) return;
    sequence.current += 1; recovery.current = null; commit(initial);
  }
  useEffect(() => {
    mounted.current = true;
    const clear = () => {
      sequence.current += 1; locked.current = false;
      pending.current?.abort(); pending.current = null;
      // Retirar datos visibles, pero conservar en memoria la referencia mínima de reconciliación.
      const incierto = ['guardando', 'incierto', 'consultando'].includes(current.current.status);
      if (!incierto) recovery.current = null;
      commit({ ...initial, status: incierto ? 'incierto' : 'busqueda' });
    };
    window.addEventListener('pagehide', clear);
    return () => {
      mounted.current = false; sequence.current += 1;
      pending.current?.abort(); pending.current = null; recovery.current = null;
      window.removeEventListener('pagehide', clear);
    };
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
  async function ejecutar(tipo, entrada) {
    if (locked.current || Date.now() < until.current) return;
    const prev = current.current;
    if (tipo === 'confirmar' && (!['revision', 'ausente'].includes(prev.status) || !prev.propuesta)) return;
    if (tipo === 'resultado' && !['incierto', 'ausente'].includes(prev.status)) return;
    if (tipo === 'proponer' && prev.status !== 'busqueda') return;
    locked.current = true;
    const operation = ++sequence.current;
    const controller = new AbortController(); pending.current = controller;
    commit({ ...prev, failure: null, status: tipo === 'proponer' ? 'preparando' : tipo === 'confirmar' ? 'guardando' : 'consultando' });
    try {
      if (tipo === 'proponer') {
        const propuesta = await proponerReserva(entrada, controller.signal);
        if (mounted.current && operation === sequence.current) commit({ ...initial, status: 'revision', propuesta });
      } else {
        const p = prev.propuesta;
        const referencia = p ? { propuesta: p.propuesta, pacienteEsperado: p.paciente.id_paciente } : recovery.current;
        recovery.current = referencia;
        const result = await (tipo === 'confirmar'
          ? confirmarReserva({ ...referencia, motivo_breve: p.motivo_breve }, controller.signal)
          : consultarResultadoReserva(referencia, controller.signal));
        if (!mounted.current || operation !== sequence.current) return;
        if (tipo === 'resultado' && !result.registrada) commit({ ...prev, status: 'ausente', failure: null });
        else {
          recovery.current = null;
          commit({ ...initial, status: 'registrada', cita: tipo === 'confirmar' ? result.data : result.cita,
            repetida: tipo === 'confirmar' ? result.meta.repetida : true });
        }
      }
    } catch (error) {
      if (!mounted.current || operation !== sequence.current) return;
      const failure = describirError(error, { operation: 'reserva', allowedFields: ['fecha', 'motivo_breve', 'monto_bob', 'propuesta', 'revision_turno'] });
      const code = error.response?.data?.error?.code;
      if (failure.status === 409) failure.message = mensajes[code] || 'La selección cambió. Consulta los turnos antes de continuar.';
      if (failure.status === 404) failure.message = 'No se encontró una selección disponible. Vuelve a consultar.';
      if (failure.status === 419) failure.message = 'La protección de la sesión ha caducado. No se reenviará la reserva automáticamente.';
      if (failure.retryAfter) { until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter); }
      // Un timeout HTTP/proxy o estado ajeno al contrato tampoco demuestra rollback.
      const rechazoConocido = [400, 401, 403, 404, 405, 409, 419, 422, 429].includes(failure.status);
      const incierto = tipo === 'resultado' || (tipo === 'confirmar' && error.reservaEnviada !== false && !rechazoConocido);
      if (incierto) {
        failure.message = 'No pudimos confirmar si la cita se registró. Consulta el resultado antes de decidir otro envío.';
        commit({ ...prev, status: 'incierto', failure });
      } else if (tipo === 'confirmar' && ([419, 429].includes(failure.status)
        || (error.reservaEnviada === false && (!failure.status || failure.status >= 500)))) {
        if (!failure.status) failure.message = 'No se envió la reserva: no pudimos preparar la protección de la sesión. Comprueba la conexión y confirma solo cuando decidas continuar.';
        commit({ ...prev, status: prev.status, failure });
      } else commit({ ...initial, status: 'error', failure });
    } finally { if (operation === sequence.current) { locked.current = false; pending.current = null; } }
  }
  return { ...state, remaining, peligro, reset, proponer: entrada => ejecutar('proponer', entrada),
    confirmar: () => ejecutar('confirmar'), consultar: () => ejecutar('resultado') };
}
