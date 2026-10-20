import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';

const fields = ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'telefono', 'sexo', 'direccion', 'correo_contacto', 'correo_acceso', 'password', 'password_confirmation', 'codigo_activacion', 'codigo', 'identidad_verificada', 'id_paciente'];

export function useRegistrationSubmission(form) {
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState(null);
  const [remaining, setRemaining] = useState(0);
  const until = useRef(0);
  const locked = useRef(false);
  const mounted = useRef(false);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => {
    if (!remaining) return;
    const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000);
    return () => clearInterval(timer);
  }, [remaining]);
  function report(next) {
    setFailure(next);
    requestAnimationFrame(() => (form.current?.querySelector('[aria-invalid="true"]') || form.current?.querySelector('[role="alert"]'))?.focus());
  }
  async function execute(operation) {
    if (locked.current || Date.now() < until.current) return;
    locked.current = true; setBusy(true); setFailure(null);
    try { await operation(); }
    catch (error) {
      if (!mounted.current) return;
      const next = describirError(error, { operation: 'registration', allowedFields: fields });
      const code = error.response?.data?.error?.code;
      if (code === 'REGISTRO_VERIFICACION_INVALIDA') next.message = 'No se pudo verificar el código o el proceso. Comprueba el código más reciente; si no está disponible, solicita reenvío o asistencia en Recepción.';
      if (code === 'ACTIVACION_NO_PERMITIDA') next.message = 'La ficha no admite esta emisión. Comprueba presencialmente su identidad, CI y que no tenga una cuenta vinculada.';
      if (code === 'AUTH_SESION_EXISTENTE') next.message = 'Hay una sesión activa en este navegador. Comprueba la sesión y ciérrala antes de continuar con el registro.';
      if (next.retryAfter) { until.current = Date.now() + next.retryAfter * 1000; setRemaining(next.retryAfter); }
      report(next);
    } finally { locked.current = false; if (mounted.current) setBusy(false); }
  }
  return { busy, failure, remaining, execute, report };
}
