import { useEffect, useRef, useState } from 'react';
import { describirError } from '../../../utils/httpError';

const allowedFields = ['correo_acceso', 'current_password', 'password', 'password_confirmation'];

export function usePasswordSubmission(form) {
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState(null);
  const [remaining, setRemaining] = useState(0);
  const [waitUntil, setWaitUntil] = useState(0);
  const inFlight = useRef(false);

  useEffect(() => {
    if (!waitUntil) return;
    const tick = () => setRemaining(Math.max(0, Math.ceil((waitUntil - Date.now()) / 1000)));
    tick();
    const timer = setInterval(tick, 1000);
    return () => clearInterval(timer);
  }, [waitUntil]);

  function report(next) {
    setFailure(next);
    requestAnimationFrame(() => {
      (form.current?.querySelector('[aria-invalid="true"]') || form.current?.querySelector('[role="alert"]'))?.focus();
    });
  }

  async function execute(operation) {
    if (inFlight.current || remaining > 0) return;
    inFlight.current = true; setBusy(true); setFailure(null);
    try { await operation(); }
    catch (error) {
      const next = describirError(error, { allowedFields, operation: 'password' });
      if (next.retryAfter) {
        setWaitUntil(Date.now() + next.retryAfter * 1000);
        setRemaining(next.retryAfter);
      }
      report(next);
    } finally { inFlight.current = false; setBusy(false); }
  }

  return { busy, failure, remaining, execute, report };
}
