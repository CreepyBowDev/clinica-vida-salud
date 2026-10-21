import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';
import { camposMedico } from '../services/medico.service';

export function useDoctorSubmission(form, creating = false) {
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
    tick(); const timer = setInterval(tick, 1000);
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
      const next = describirError(error, { operation: 'doctor', allowedFields: [...camposMedico, 'activo', 'especialidades', 'actualizado_en_original'] });
      const details = error.response?.data?.error?.details;
      if (next.status === 422 && Object.keys(details || {}).some(key => /^especialidades\.\d+$/.test(key))) next.fields.especialidades = 'Revisa las especialidades seleccionadas; alguna opción no es válida.';
      if (next.status === 404) next.message = 'No se encontró el médico solicitado.';
      if (next.status === 409 && error.response?.data?.error?.code === 'MEDICO_EDICION_OBSOLETA') {
        next.stale = true; next.message = 'El registro profesional cambió desde que lo consultaste. Tu borrador se conserva; revisa la versión actual antes de continuar.';
      }
      if (next.status === 409 && error.response?.data?.error?.code === 'MEDICO_CONFLICTO_UNICIDAD') next.message = 'No se pudo asignar un código único al profesional. Consulta los registros antes de volver a intentarlo.';
      if (next.status === 409 && error.response?.data?.error?.code === 'MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS') next.message = 'No se puede retirar una especialidad con citas futuras Programadas o Confirmadas. No se guardó ningún cambio. Revisa tu selección; las citas se gestionan por Recepción.';
      if (next.status === 409 && error.response?.data?.error?.code === 'MEDICO_SIN_ESPECIALIDADES_HABILITADAS') {
        next.message = 'El médico debe conservar al menos una especialidad habilitada.';
        next.fields.especialidades = next.message;
      }
      if (next.status === 409 && error.response?.data?.error?.code === 'MEDICO_VINCULO_INCOMPATIBLE') {
        next.incompatible = true;
        next.message = 'No se pudo activar al profesional porque su cuenta no conserva un rol y vínculo compatibles. Revisa el vínculo en gestión de cuentas.';
      }
      if (!next.status || next.status >= 500) {
        next.uncertain = true;
        next.message = creating ? 'No pudimos confirmar el alta. El médico podría haberse registrado. Consulta el listado antes de iniciar otro registro.' : 'No pudimos confirmar el cambio. Consulta la versión actual antes de decidir si corresponde otro envío.';
      }
      if (next.retryAfter) { setUntil(Date.now() + next.retryAfter * 1000); setRemaining(next.retryAfter); }
      report(next); return { failure: next };
    } finally { locked.current = false; if (mounted.current) setBusy(false); }
  }
  return { busy, failure, remaining, execute, report };
}
