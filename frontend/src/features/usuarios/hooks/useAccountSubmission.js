import { useEffect, useRef, useState } from 'react';
import { describirErrorCuenta } from '../utils/accountError';

export function useAccountSubmission(form) {
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
    requestAnimationFrame(() => (form.current?.querySelector('[aria-invalid="true"]') || form.current?.querySelector('[role="alert"]'))?.focus());
  }

  async function execute(operation) {
    if (locked.current || Date.now() < until) return;
    locked.current = true; setBusy(true); setFailure(null);
    try { await operation(); }
    catch (error) {
      if (!mounted.current) return;
      const next = describirErrorCuenta(error);
      if (next.retryAfter) { setUntil(Date.now() + next.retryAfter * 1000); setRemaining(next.retryAfter); }
      report(next);
    } finally { locked.current = false; if (mounted.current) setBusy(false); }
  }
  return { busy, failure, remaining, execute, report };
}
