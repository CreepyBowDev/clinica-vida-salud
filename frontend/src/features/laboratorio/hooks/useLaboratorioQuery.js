import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { laboratorioError } from '../utils/laboratorioError';

// Datos ligados a identidad, recurso, filtros y revisión; no caché clínica global.
export function useLaboratorioQuery(load, parameters) {
  const { status, usuario } = useAuth();
  const identity = JSON.stringify([status, usuario?.id_usuario, usuario?.rol]);
  const [revision, setRevision] = useState(0), [hidden, setHidden] = useState(false), [paused, setPaused] = useState(false);
  const [result, setResult] = useState(null), [clock, setClock] = useState(Date.now());
  const limit = useRef(null), sequence = useRef(0), pending = useRef(null);
  const key = JSON.stringify([identity, parameters, revision, hidden, paused]);
  useEffect(() => {
    const clear = () => { sequence.current++; pending.current?.abort(); setResult(null); };
    const hide = () => { clear(); setHidden(true); };
    const show = () => { setHidden(false); setRevision(v => v + 1); };
    const unsubscribe = escucharCambioSesion(() => { clear(); setPaused(true); });
    window.addEventListener('pagehide', hide); window.addEventListener('pageshow', show);
    return () => { unsubscribe(); window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show); };
  }, []);
  useEffect(() => {
    const timer = setInterval(() => setClock(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  useEffect(() => {
    const operation = ++sequence.current, controller = new AbortController();
    pending.current = controller;
    if (status !== 'authenticated' || hidden || paused) return () => controller.abort();
    if (limit.current?.identity === identity && limit.current.until > Date.now()) {
      setResult({ key, failure: limit.current.failure }); return () => controller.abort();
    }
    load(JSON.parse(key)[1], controller.signal).then(data => {
      if (operation === sequence.current && !controller.signal.aborted) setResult({ key, data });
    }, error => {
      if (operation !== sequence.current || controller.signal.aborted) return;
      const failure = laboratorioError(error);
      if (failure.retryAfter) { limit.current = { identity, until: Date.now() + failure.retryAfter * 1000, failure }; setClock(Date.now()); }
      setResult({ key, failure });
    });
    return () => controller.abort();
  }, [load, key, identity, status, hidden, paused]);
  const current = status === 'authenticated' && !hidden && !paused && result?.key === key ? result : null;
  const remaining = limit.current?.identity === identity ? Math.max(0, Math.ceil((limit.current.until - clock) / 1000)) : 0;
  return { data: current?.data, failure: paused ? { message: 'La sesión cambió. Vuelve a consultar antes de continuar.' } : current?.failure,
    loading: !paused && !current, remaining, reload: () => {
      if (!(limit.current?.identity === identity && limit.current.until > Date.now())) { setPaused(false); setRevision(v => v + 1); }
    } };
}
