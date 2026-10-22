import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';

const messages = { MEDICO_EDICION_OBSOLETA: 'El médico o sus horarios cambiaron. Tu borrador se conserva; vuelve al listado y revisa los datos actuales antes de iniciar otra propuesta.',
  HORARIO_INTERVALO_INVALIDO: 'La hora final debe ser posterior a la inicial, sin cruzar medianoche.',
  DURACION_INCOMPATIBLE: 'La duración vigente no cabe en este horario. Revisa el intervalo.',
  CONSULTORIO_NO_DISPONIBLE: 'El médico necesita un consultorio fijo activo para habilitar horarios.',
  PLANIFICACION_CONFLICTO: 'Hay conflictos de horario u ocupación. Consulta el impacto actualizado.',
  HORARIO_CITAS_SIN_COBERTURA: 'El cambio deja citas vigentes sin cobertura completa. Recepción gestiona las reprogramaciones autorizadas; no se modifican citas desde aquí.' };
export function useHorarioSubmission(form, configuracion = false) {
  const [busy, setBusy] = useState('');
  const [failure, setFailure] = useState(null);
  const [blocked, setBlocked] = useState(false);
  const [remaining, setRemaining] = useState(0);
  const [until, setUntil] = useState(0);
  const mounted = useRef(true), sequence = useRef(0), controller = useRef(null), writing = useRef(false);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; sequence.current++; controller.current?.abort(); }; }, []);
  useEffect(() => { if (!until) return; const tick = () => setRemaining(Math.max(0, Math.ceil((until - Date.now()) / 1000))); tick(); const timer = setInterval(tick, 1000); return () => clearInterval(timer); }, [until]);
  function report(next) {
    setFailure(next);
    if (next) requestAnimationFrame(() => (form.current?.querySelector('[aria-invalid="true"]:not(:disabled)') || form.current?.querySelector('[role="alert"]'))?.focus());
  }
  function cancel() { sequence.current++; controller.current?.abort(); if (!writing.current) setBusy(''); }
  async function execute(kind, task) {
    if (writing.current || blocked || remaining) return;
    controller.current?.abort(); const abort = new AbortController(); controller.current = abort;
    const token = ++sequence.current;
    if (kind === 'write') writing.current = true;
    setBusy(kind); setFailure(null);
    try {
      const data = await task(abort.signal);
      if (mounted.current && token === sequence.current) return { data };
    } catch (error) {
      if (!mounted.current || token !== sequence.current || abort.signal.aborted) return;
      const next = describirError(error, { operation: 'horario', allowedFields: configuracion ? ['duracion_turno_minutos', 'actualizado_en_original', 'page', 'per_page'] : ['dia_semana', 'hora_inicio', 'hora_fin', 'activo', 'actualizado_en_original', 'id_horario', 'page', 'per_page'] });
      const code = error.response?.data?.error?.code;
      if (next.status === 409 && Object.hasOwn(messages, code)) next.message = messages[code];
      if (configuracion && next.status === 409 && code === 'CONFIGURACION_EDICION_OBSOLETA') next.message = 'La configuración cambió. Conservamos tu propuesta; consulta la configuración actual antes de iniciar otra revisión.';
      if (configuracion && next.status === 409 && code === 'DURACION_INCOMPATIBLE') next.message = 'La duración propuesta no cabe en todos los horarios activos, incluidos los de Médicos inactivos. Consulta el impacto actualizado.';
      if (next.status === 404) next.message = configuracion ? 'No se encontró la configuración de citas. Vuelve a consultar la configuración actual.' : 'No se encontró el médico o el horario solicitado. Vuelve a consultar el listado.';
      if (code === 'MEDICO_EDICION_OBSOLETA' || [403, 404].includes(next.status)) setBlocked(true);
      if (configuracion && code === 'CONFIGURACION_EDICION_OBSOLETA') setBlocked(true);
      if (kind === 'write' && (!next.status || next.status >= 500)) { setBlocked(true); next.message = configuracion ? 'No pudimos confirmar el cambio. Puede haberse guardado. Consulta la configuración actual antes de decidir otro envío.' : 'No pudimos confirmar el cambio. Puede haberse guardado. Consulta los horarios actuales antes de decidir otro envío.'; }
      else if (!next.status) next.message = 'No pudimos consultar el impacto. Comprueba tu conexión y vuelve a consultar.';
      if (next.retryAfter) { setUntil(Date.now() + next.retryAfter * 1000); setRemaining(next.retryAfter); }
      report(next); return { failure: next };
    } finally { if (kind === 'write') writing.current = false; if (mounted.current && token === sequence.current) setBusy(''); }
  }
  return { busy, failure, blocked, remaining, execute, cancel, report };
}
