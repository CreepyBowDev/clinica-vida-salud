import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';

export function useSpecialtySubmission(form, creating = false) {
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState(null);
  const [remaining, setRemaining] = useState(0);
  const [until, setUntil] = useState(0);
  const locked = useRef(false);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => {
    if (!until) return;
    const tick = () => setRemaining(Math.max(0, Math.ceil((until - Date.now()) / 1000)));
    tick();
    const timer = setInterval(tick, 1000);
    return () => clearInterval(timer);
  }, [until]);
  function report(next) {
    setFailure(next);
    requestAnimationFrame(() => (form.current?.querySelector('[aria-invalid="true"]:not(:disabled)') || form.current?.querySelector('[role="alert"]'))?.focus());
  }
  async function execute(task) {
    if (locked.current || Date.now() < until) return;
    locked.current = true; setBusy(true); setFailure(null);
    try {
      const data = await task();
      if (mounted.current) return { data };
    } catch (error) {
      if (!mounted.current) return;
      const next = describirError(error, { operation: 'specialty', allowedFields: ['nombre', 'descripcion', 'activa', 'actualizado_en_original'] });
      const code = error.response?.data?.error?.code;
      if (next.status === 404) next.message = 'No se encontró la especialidad solicitada. Vuelve al catálogo para consultar los registros disponibles.';
      if (next.status === 409 && code === 'ESPECIALIDAD_NOMBRE_DUPLICADO') {
        next.message = 'Ya existe una especialidad con ese nombre. Revisa el catálogo o utiliza otro nombre.';
        next.fields.nombre = 'Este nombre ya está registrado.';
      }
      if (next.status === 409 && code === 'ESPECIALIDAD_EDICION_OBSOLETA') {
        next.stale = true;
        next.message = 'La especialidad cambió desde que la consultaste. Tu borrador se conserva; revisa la versión actual antes de continuar.';
      }
      if (!next.status || next.status >= 500) {
        next.uncertain = true;
        next.message = creating
          ? 'No pudimos confirmar el alta. La especialidad podría haberse creado. Consulta el catálogo antes de iniciar otro registro.'
          : 'No pudimos confirmar el cambio. Consulta la versión actual antes de decidir si corresponde otro envío.';
      }
      if (next.retryAfter) { setUntil(Date.now() + next.retryAfter * 1000); setRemaining(next.retryAfter); }
      report(next);
      return { failure: next };
    } finally { locked.current = false; if (mounted.current) setBusy(false); }
  }
  return { busy, failure, remaining, execute, report };
}
