import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import { describirError } from '../../../utils/httpError';
import { listarRecordatorios } from '../services/recordatorio.service';

function errorConsulta(error) {
  const failure = describirError(error, { operation: 'query', allowedFields: [] });
  failure.message = ({ 400: 'La consulta no es válida. Revisa los filtros.', 404: 'Los recordatorios no están disponibles o no tienes acceso.',
    419: 'La protección de la sesión ha caducado. Comprueba tu sesión y vuelve a consultar.',
    422: 'Revisa las fechas indicadas antes de volver a consultar.' })[failure.status] ?? failure.message;
  if (!error.response || failure.status >= 500) failure.message = 'No pudimos consultar los recordatorios. Comprueba tu conexión y vuelve a intentarlo.';
  const value = error.response?.headers?.['retry-after'];
  if (failure.status === 429 && typeof value === 'string' && !Number.isFinite(Number(value))) {
    const date = Date.parse(value);
    if (Number.isFinite(date)) failure.retryAfter = Math.max(0, Math.ceil((date - Date.now()) / 1000));
  }
  return failure;
}

export function useRecordatoriosQuery(parameters) {
  const { status, usuario } = useAuth();
  const identity = JSON.stringify([status, usuario?.id_usuario, usuario?.rol]);
  const enabled = status === 'authenticated' && [ROLES.PACIENTE, ROLES.ADMINISTRADOR].includes(usuario?.rol);
  const [revision, setRevision] = useState(0), [hidden, setHidden] = useState(false), [paused, setPaused] = useState(false);
  const [result, setResult] = useState(null), [clock, setClock] = useState(Date.now());
  const pending = useRef(null), sequence = useRef(0), limit = useRef(null);
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
    if (!enabled || hidden || paused) return () => controller.abort();
    if (limit.current?.identity === identity && limit.current.until > Date.now()) {
      setResult({ key, failure: limit.current.failure }); return () => controller.abort();
    }
    listarRecordatorios({ ...JSON.parse(key)[1], rol: usuario.rol }, controller.signal).then(data => {
      if (operation === sequence.current && !controller.signal.aborted) setResult({ key, data });
    }, error => {
      if (operation !== sequence.current || controller.signal.aborted) return;
      const failure = errorConsulta(error);
      if (failure.retryAfter) { limit.current = { identity, until: Date.now() + failure.retryAfter * 1000, failure }; setClock(Date.now()); }
      setResult({ key, failure });
    });
    return () => controller.abort();
  }, [key, identity, enabled, hidden, paused]);
  const current = enabled && !hidden && !paused && result?.key === key ? result : null;
  const remaining = limit.current?.identity === identity ? Math.max(0, Math.ceil((limit.current.until - clock) / 1000)) : 0;
  return { data: current?.data, loading: !paused && !current,
    failure: paused ? { message: 'La sesión cambió. Vuelve a consultar antes de continuar.' } : current?.failure, remaining,
    reload: () => { if (!(limit.current?.identity === identity && limit.current.until > Date.now())) { setPaused(false); setRevision(v => v + 1); } } };
}
