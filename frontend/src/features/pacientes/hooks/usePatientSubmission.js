import { useEffect, useRef, useState } from 'react';
import { describirErrorPaciente } from '../utils/patientError';

export function usePatientSubmission(form, { operation = 'create' } = {}) {
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
      const next = describirErrorPaciente(error, { writing: true, operation });
      if (next.retryAfter) { setUntil(Date.now() + next.retryAfter * 1000); setRemaining(next.retryAfter); }
      report(next);
      return { failure: next };
    } finally { locked.current = false; if (mounted.current) setBusy(false); }
  }
  return { busy, failure, remaining, execute, report };
}
