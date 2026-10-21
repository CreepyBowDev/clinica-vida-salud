import { useEffect, useRef, useState } from 'react';
import { describirErrorCambioCorreo } from '../utils/emailChangeError';

export function useEmailChangeSubmission(form) {
  const [busy, setBusy] = useState(null);
  const [failure, setFailure] = useState(null);
  const [retryUntil, setRetryUntil] = useState(0);
  const [sendUntil, setSendUntil] = useState(0);
  const [now, setNow] = useState(Date.now());
  const mounted = useRef(false);
  const locked = useRef(false);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => {
    if (!retryUntil && !sendUntil) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [retryUntil, sendUntil]);
  function report(next) {
    setFailure(next);
    requestAnimationFrame(() => (form.current?.querySelector('[aria-invalid="true"]:not(:disabled)') || form.current?.querySelector('[role="alert"]'))?.focus());
  }
  async function run(action, task) {
    const emission = ['request', 'resend'].includes(action);
    if (locked.current || (action !== 'check' && Date.now() < Math.max(retryUntil, emission ? sendUntil : 0))) return;
    locked.current = true; setBusy(action); setFailure(null);
    try {
      const data = await task();
      if (!mounted.current) return;
      if (emission) { setNow(Date.now()); setSendUntil(Date.now() + 60000); }
      return { data };
    } catch (error) {
      if (!mounted.current) return;
      const next = describirErrorCambioCorreo(error, action);
      if (next.retryAfter) { setNow(Date.now()); setRetryUntil(Date.now() + next.retryAfter * 1000); }
      report(next); return { failure: next };
    } finally { locked.current = false; if (mounted.current) setBusy(null); }
  }
  return { busy, failure, report, run,
    remaining: Math.max(0, Math.ceil((retryUntil - now) / 1000)),
    sendRemaining: Math.max(0, Math.ceil((Math.max(sendUntil, retryUntil) - now) / 1000)),
  };
}
