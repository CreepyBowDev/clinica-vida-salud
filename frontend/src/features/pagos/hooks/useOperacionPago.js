import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { operarPago } from '../services/pago.service';
import { errorPago } from '../utils/pagoError';

// Una solicitud incierta conserva en memoria exactamente su clave y entrada.
// No se persiste: tras recarga se consulta el intento local desde la obligación.
export function useOperacionPago() {
  const [state, setState] = useState({}), [clock, setClock] = useState(Date.now());
  const locked = useRef(false), sequence = useRef(0), mounted = useRef(false), pending = useRef(null), until = useRef(0);
  useEffect(() => {
    mounted.current = true;
    const clear = () => { sequence.current++; locked.current = false; pending.current = null; setState({ paused: true }); };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    // Una acción corroborada no se mantiene tras volver a la pestaña.
    const invalidate = () => { if (!locked.current) setState(v => v.response ? { ...v, stale: true, response: { ...v.response, data: { ...v.response.data, accion: null } } } : v); };
    window.addEventListener('focus', invalidate);
    return () => { mounted.current = false; sequence.current++; unsubscribe(); window.removeEventListener('pagehide', clear); window.removeEventListener('focus', invalidate); };
  }, []);
  useEffect(() => { const timer = setInterval(() => setClock(Date.now()), 1000); return () => clearInterval(timer); }, []);
  useEffect(() => {
    if (!state.busy && !state.uncertain) return;
    const warn = e => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
  }, [state.busy, state.uncertain]);
  async function ejecutar(input, recuperar = false) {
    if (locked.current || Date.now() < until.current) return;
    if (pending.current && !recuperar) return;
    const solicitud = recuperar ? pending.current : { ...input, clave: crypto.randomUUID() };
    if (!solicitud) return;
    pending.current = solicitud; locked.current = true;
    const seq = ++sequence.current;
    setState({ busy: true, tipo: solicitud.tipo });
    try {
      const response = await operarPago(solicitud);
      if (!mounted.current || seq !== sequence.current) return;
      pending.current = null;
      until.current = Date.now() + Math.max(response.retryAfter, response.status === 202 ? 5 : 0) * 1000;
      setClock(Date.now()); setState({ response, tipo: solicitud.tipo });
    } catch (error) {
      if (!mounted.current || seq !== sequence.current) return;
      const failure = errorPago(error);
      const uncertain = error.pagoEnviado !== false && (!failure.status || failure.status >= 500);
      // Incluso una denegación exige revisar antes de ofrecer otra operación.
      if (!uncertain) pending.current = null;
      until.current = Date.now() + Math.max(failure.retryAfter, uncertain ? 5 : 0) * 1000;
      setClock(Date.now()); setState({ failure, uncertain, needsReview: true, tipo: solicitud.tipo });
    } finally { if (seq === sequence.current) locked.current = false; }
  }
  return { ...state, remaining: Math.max(0, Math.ceil((until.current - clock) / 1000)),
    ejecutar, recuperar: () => ejecutar(null, true) };
}
