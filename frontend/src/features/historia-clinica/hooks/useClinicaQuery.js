import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../hooks/useAuth';
import { escucharCambioSesion } from '../../../api/api';
import { describirErrorClinico } from '../utils/clinicaError';

// El resultado pertenece a una identidad, recurso, página y revisión concretos.
export function useClinicaQuery(load, parameters) {
  const { status, usuario } = useAuth();
  const identity = JSON.stringify([status, usuario?.id_usuario, usuario?.rol]);
  const [revision, setRevision] = useState(0);
  const [hidden, setHidden] = useState(false);
  const [sessionPaused, setSessionPaused] = useState(false);
  const [result, setResult] = useState(null);
  const [clock, setClock] = useState(Date.now());
  const limit = useRef(null);
  const sequence = useRef(0);
  const pending = useRef(null);
  const key = JSON.stringify([identity, parameters, revision, hidden, sessionPaused]);
  useEffect(() => {
    const hide = () => { sequence.current += 1; pending.current?.abort(); setHidden(true); setResult(null); };
    const show = () => { setHidden(false); setRevision(value => value + 1); };
    window.addEventListener('pagehide', hide); window.addEventListener('pageshow', show);
    return () => { window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show); };
  }, []);
  useEffect(() => escucharCambioSesion(() => {
    sequence.current += 1; pending.current?.abort(); setResult(null); setSessionPaused(true);
  }), []);
  useEffect(() => {
    const timer = setInterval(() => setClock(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  useEffect(() => {
    const operation = ++sequence.current, controller = new AbortController();
    pending.current = controller;
    if (status !== 'authenticated' || hidden || sessionPaused) return () => controller.abort();
    if (limit.current?.identity === identity && limit.current.until > Date.now()) {
      setResult({ key, failure: limit.current.failure }); return () => controller.abort();
    }
    load(JSON.parse(key)[1], controller.signal).then(data => {
      if (operation === sequence.current && !controller.signal.aborted) setResult({ key, data });
    }, error => {
      if (operation !== sequence.current || controller.signal.aborted) return;
      const failure = describirErrorClinico(error);
      failure.message = ({ 404: 'La información clínica no está disponible o no tienes acceso a ella.',
        419: 'La protección de la sesión ha caducado. Comprueba tu sesión y vuelve a consultar.',
        422: 'La consulta no es válida. Revisa el recurso solicitado.' })[failure.status] ?? failure.message;
      if (!error.response || failure.status >= 500) failure.message = 'No pudimos consultar la información clínica. Comprueba tu conexión y vuelve a intentarlo.';
      if (failure.retryAfter) { limit.current = { identity, until: Date.now() + failure.retryAfter * 1000, failure }; setClock(Date.now()); }
      setResult({ key, failure });
    });
    return () => controller.abort();
  }, [load, key, identity, status, hidden, sessionPaused]);
  const current = status === 'authenticated' && !hidden && result?.key === key ? result : null;
  const remaining = limit.current?.identity === identity ? Math.max(0, Math.ceil((limit.current.until - clock) / 1000)) : 0;
  const paused = status === 'authenticated' && !hidden && sessionPaused;
  return { data: paused ? undefined : current?.data, failure: paused ? { message: 'La sesión cambió. Vuelve a consultar antes de continuar.', fields: {} } : current?.failure,
    loading: !paused && !current, remaining,
    reload: () => { if (!(limit.current?.identity === identity && limit.current.until > Date.now())) { setSessionPaused(false); setRevision(value => value + 1); } } };
}
