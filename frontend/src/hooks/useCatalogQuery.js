import { useEffect, useRef, useState } from 'react';
import { describirError } from '../utils/httpError';

// Consulta paginada compartida por Médicos y Especialidades; nunca reintenta sola.
export function useCatalogQuery(load, parameters, allowedFields) {
  const key = JSON.stringify(parameters);
  const fieldsKey = JSON.stringify(allowedFields);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState(null);
  const [remaining, setRemaining] = useState(0);
  const [until, setUntil] = useState(0);
  const limit = useRef(null);
  useEffect(() => {
    if (!until) return;
    const tick = () => setRemaining(Math.max(0, Math.ceil((until - Date.now()) / 1000)));
    tick();
    const timer = setInterval(tick, 1000);
    return () => clearInterval(timer);
  }, [until]);
  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    if (limit.current?.until > Date.now()) {
      setResult({ key, revision, failure: limit.current.failure });
      return () => controller.abort();
    }
    load(JSON.parse(key), controller.signal).then(data => {
      if (active) setResult({ key, revision, data });
    }, error => {
      if (!active) return;
      const failure = describirError(error, { operation: 'catalog', allowedFields: JSON.parse(fieldsKey) });
      if (!error.response) failure.message = 'No pudimos consultar el listado. Comprueba tu conexión y vuelve a intentarlo.';
      if (failure.status === 419) failure.message = 'La protección de la sesión ha caducado. Comprueba tu sesión y vuelve a consultar.';
      if (failure.retryAfter) {
        const deadline = Date.now() + failure.retryAfter * 1000;
        limit.current = { until: deadline, failure };
        setUntil(deadline); setRemaining(failure.retryAfter);
      }
      setResult({ key, revision, failure });
    });
    return () => { active = false; controller.abort(); };
  }, [load, key, fieldsKey, revision]);
  const current = result?.key === key && result.revision === revision ? result : null;
  return { data: current?.data, failure: current?.failure, loading: !current, remaining,
    reload: () => setRevision(value => value + 1) };
}
