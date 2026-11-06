import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { errorPago } from '../utils/pagoError';

export function usePagoQuery(load, parameters, enabled = true) {
  const { status, usuario } = useAuth();
  const identity = JSON.stringify([status, usuario?.id_usuario, usuario?.rol]);
  const [revision, setRevision] = useState(0), [paused, setPaused] = useState(false), [result, setResult] = useState(null), [clock, setClock] = useState(Date.now());
  const sequence = useRef(0), pending = useRef(null), until = useRef(0);
  const key = JSON.stringify([identity, parameters, revision]);
  useEffect(() => {
    const clear = () => { sequence.current++; pending.current?.abort(); setResult(null); setPaused(true); };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    return () => { unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => {
    const timer = setInterval(() => setClock(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  useEffect(() => {
    const seq = ++sequence.current, controller = new AbortController(); pending.current = controller;
    if (!enabled || paused || status !== 'authenticated') return () => controller.abort();
    if (Date.now() < until.current) { setResult({ key, failure: { message: 'Espera antes de volver a consultar.' } }); return () => controller.abort(); }
    load(JSON.parse(key)[1], controller.signal).then(data => {
      if (seq === sequence.current && !controller.signal.aborted) setResult({ key, data });
    }, error => {
      if (seq !== sequence.current || controller.signal.aborted) return;
      const failure = errorPago(error); until.current = Date.now() + failure.retryAfter * 1000;
      setResult({ key, failure }); setClock(Date.now());
    });
    return () => controller.abort();
  }, [load, key, status, enabled, paused]);
  const current = enabled && !paused && status === 'authenticated' && result?.key === key ? result : null;
  return { data: current?.data, failure: paused ? { message: 'La sesión o la página cambió. Vuelve a consultar.' } : current?.failure,
    loading: enabled && status === 'authenticated' && !paused && !current, remaining: Math.max(0, Math.ceil((until.current - clock) / 1000)),
    reload: () => { if (Date.now() >= until.current) { setPaused(false); setRevision(n => n + 1); } } };
}
