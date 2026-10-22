import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';

const messages = {
  CONSULTORIO_CODIGO_DUPLICADO: 'Este código ya está registrado. Introduce otro código.',
  CONSULTORIO_EDICION_OBSOLETA: 'El consultorio cambió desde que lo consultaste. Conservamos tu borrador; consulta y revisa los datos actuales antes de continuar.',
  CONSULTORIO_CON_ASIGNACIONES: 'No se puede desactivar: tiene médicos asignados, incluidos los inactivos. Resuelve las asignaciones mediante las operaciones autorizadas.',
  CONSULTORIO_CON_OBLIGACIONES: 'No se puede desactivar: tiene citas vigentes. Recepción debe gestionarlas mediante las operaciones autorizadas.',
};

export function useConsultorioSubmission(form, creating = false) {
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState(null);
  const [until, setUntil] = useState(0);
  const [remaining, setRemaining] = useState(0);
  const locked = useRef(false);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => {
    if (!until) return;
    const tick = () => setRemaining(Math.max(0, Math.ceil((until - Date.now()) / 1000)));
    tick(); const timer = setInterval(tick, 1000);
    return () => clearInterval(timer);
  }, [until]);
  function report(next) {
    setFailure(next);
    if (next) requestAnimationFrame(() => (form.current?.querySelector('[aria-invalid="true"]:not(:disabled)') || form.current?.querySelector('[role="alert"]'))?.focus());
  }
  async function execute(task) {
    if (locked.current || Date.now() < until) return;
    locked.current = true; setBusy(true); setFailure(null);
    try {
      const data = await task();
      if (mounted.current) return { data };
    } catch (error) {
      if (!mounted.current) return;
      const next = describirError(error, { operation: 'consultorio', allowedFields: ['codigo_consultorio', 'nombre', 'activo', 'actualizado_en_original'] });
      const code = error.response?.data?.error?.code;
      if (next.status === 404) next.message = 'No se encontró el consultorio solicitado.';
      if (next.status === 409 && messages[code]) next.message = messages[code];
      if (next.status === 409 && code === 'CONSULTORIO_CODIGO_DUPLICADO') next.fields.codigo_consultorio = messages[code];
      if (next.status === 409 && code === 'CONSULTORIO_EDICION_OBSOLETA') next.stale = true;
      if (next.status === 419) next.message = 'La protección de la sesión ha caducado. Revisa el formulario y vuelve a enviarlo para renovarla.';
      if (!next.status || next.status >= 500) {
        next.uncertain = true;
        next.message = creating ? 'No pudimos confirmar el alta. El consultorio podría haberse registrado. Consulta el catálogo antes de iniciar otra alta.'
          : 'No pudimos confirmar el cambio. Consulta los datos actuales antes de decidir si corresponde otro envío.';
      }
      if (next.retryAfter) { setUntil(Date.now() + next.retryAfter * 1000); setRemaining(next.retryAfter); }
      report(next); return { failure: next };
    } finally { locked.current = false; if (mounted.current) setBusy(false); }
  }
  return { busy, failure, remaining, execute, report };
}
