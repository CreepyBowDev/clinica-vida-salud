import { useEffect, useState } from 'react';
import { consultarCuenta, listarCuentas } from '../services/usuario.service';
import { describirErrorCuenta } from '../utils/accountError';

// Claves primitivas y AbortController evitan mostrar respuestas de filtros/rutas anteriores.
export function useAccountQuery(kind, parameters) {
  const key = JSON.stringify([kind, parameters]);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState(null);
  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const [type, args] = JSON.parse(key);
    const request = type === 'list' ? listarCuentas(args, controller.signal) : consultarCuenta(args, controller.signal);
    request.then(data => {
      if (active) setResult({ key, revision, data });
    }, error => {
      if (active) setResult({ key, revision, failure: describirErrorCuenta(error) });
    });
    return () => { active = false; controller.abort(); };
  }, [key, revision]);
  const current = result?.key === key && result.revision === revision ? result : null;
  return { data: current?.data, failure: current?.failure, loading: !current, reload: () => setRevision(value => value + 1) };
}
