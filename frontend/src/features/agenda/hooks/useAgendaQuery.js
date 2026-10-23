import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../../../hooks/useAuth';
import { describirError } from '../../../utils/httpError';

function errorConsulta(error, kind, allowedFields) {
  const failure = describirError(error, { operation: 'query', allowedFields });
  failure.message = ({ 404: kind === 'detail' ? 'La cita no está disponible o no tienes acceso a ella.' : 'El recurso de la consulta no está disponible.',
    419: 'La protección de la sesión ha caducado. Comprueba tu sesión y vuelve a consultar.',
    422: kind === 'detail' ? 'La consulta no es válida. Comprueba el identificador de la cita.' : 'Revisa los filtros indicados antes de volver a consultar.',
    500: 'No pudimos consultar las citas. Vuelve a intentarlo más tarde.' })[failure.status] ?? failure.message;
  if (!error.response) failure.message = kind === 'detail' ? 'No pudimos consultar la cita. Comprueba tu conexión y vuelve a intentarlo.' : 'No pudimos obtener la consulta. Comprueba tu conexión y vuelve a intentarlo.';
  return failure;
}

export function useAgendaQuery(load, parameters, { enabled = true, kind = 'detail', allowedFields = [] } = {}) {
  const { status, usuario } = useAuth();
  const identity = JSON.stringify([status, usuario?.id_usuario, usuario?.rol]);
  const [revision, setRevision] = useState(0);
  const [hidden, setHidden] = useState(false);
  const [result, setResult] = useState(null);
  const [clock, setClock] = useState(Date.now());
  const limit = useRef(null);
  const fieldsKey = JSON.stringify(allowedFields);
  const key = JSON.stringify([identity, parameters, revision, hidden, enabled, kind, fieldsKey]);
  const sequence = useRef(0);
  useEffect(() => {
    const hide = () => { sequence.current += 1; setHidden(true); setResult(null); };
    const show = () => { setHidden(false); setRevision(value => value + 1); };
    window.addEventListener('pagehide', hide);
    window.addEventListener('pageshow', show);
    return () => { window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show); };
  }, []);
  useEffect(() => {
    const timer = setInterval(() => setClock(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  useEffect(() => {
    const operation = ++sequence.current;
    const controller = new AbortController();
    if (status !== 'authenticated' || hidden || !enabled) return () => controller.abort();
    if (limit.current?.identity === identity && limit.current.until > Date.now()) {
      setResult({ key, failure: limit.current.failure });
      return () => controller.abort();
    }
    const [, args] = JSON.parse(key);
    load(args, controller.signal).then(data => {
      if (operation === sequence.current && !controller.signal.aborted) setResult({ key, data });
    }, error => {
      if (operation !== sequence.current || controller.signal.aborted || error.code === 'SESSION_SUPERSEDED') return;
      const failure = errorConsulta(error, kind, JSON.parse(fieldsKey));
      if (failure.retryAfter) {
        limit.current = { identity, until: Date.now() + failure.retryAfter * 1000, failure };
        setClock(Date.now());
      }
      setResult({ key, failure });
    });
    return () => { controller.abort(); };
  }, [load, key, identity, status, hidden, enabled, kind, fieldsKey]);
  const current = status === 'authenticated' && !hidden && enabled && result?.key === key ? result : null;
  const remaining = limit.current?.identity === identity ? Math.max(0, Math.ceil((limit.current.until - clock) / 1000)) : 0;
  return { data: current?.data, failure: current?.failure, loading: enabled && !current, remaining,
    reload: () => { if (!(limit.current?.identity === identity && limit.current.until > Date.now())) setRevision(value => value + 1); } };
}
