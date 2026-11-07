import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { escucharCambioSesion, obtenerEpocaSesion } from '../../../api/api';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import { descargarReportePdf } from '../services/reportePdf.service';

export default function DescargarReportePdf({ tipo, filtros }) {
  const pending = useRef(null), urls = useRef(new Map()), button = useRef(null);
  const restoreFocus = useRef(false);
  const [loading, setLoading] = useState(false), [message, setMessage] = useState(''), [error, setError] = useState('');
  const [until, setUntil] = useState(0), [now, setNow] = useState(Date.now());
  const remaining = Math.max(0, Math.ceil((until - now) / 1000));
  useLayoutEffect(() => {
    if (!loading && restoreFocus.current) { restoreFocus.current = false; button.current?.focus(); }
  }, [loading]);
  function limpiar() {
    pending.current?.abort(); pending.current = null;
    urls.current.forEach((timer, url) => { clearTimeout(timer); URL.revokeObjectURL(url); });
    urls.current.clear();
  }
  useLayoutEffect(() => {
    const unsubscribe = escucharCambioSesion(limpiar);
    window.addEventListener('pagehide', limpiar);
    return () => { unsubscribe(); window.removeEventListener('pagehide', limpiar); limpiar(); };
  }, []);
  useEffect(() => {
    if (!until) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [until]);
  async function descargar() {
    if (pending.current || remaining) return;
    const controller = new AbortController(), epoch = obtenerEpocaSesion();
    pending.current = controller; setLoading(true); setError(''); setMessage('Generando PDF. Espera; puedes cancelar la espera.');
    try {
      const { blob, nombre } = await descargarReportePdf(tipo, filtros, controller.signal);
      if (controller.signal.aborted || epoch !== obtenerEpocaSesion() || pending.current !== controller) return;
      const url = URL.createObjectURL(blob);
      // No revocar antes de que el navegador pueda consumir el enlace. El desmontaje también libera la URL.
      urls.current.set(url, setTimeout(() => { URL.revokeObjectURL(url); urls.current.delete(url); }, 1000));
      const link = document.createElement('a');
      link.href = url; link.download = nombre;
      document.body.appendChild(link);
      try { link.click(); } finally { link.remove(); }
      setMessage('Archivo enviado al navegador para su descarga.');
    } catch (failure) {
      if (controller.signal.aborted || epoch !== obtenerEpocaSesion() || pending.current !== controller) return;
      if (!['ERR_CANCELED', 'SESSION_SUPERSEDED'].includes(failure.code)) {
        setMessage(''); setError(failure.message);
        if (failure.retryAfter) { setUntil(Date.now() + failure.retryAfter * 1000); setNow(Date.now()); }
      }
    } finally {
      if (pending.current === controller) { pending.current = null; setLoading(false); }
    }
  }
  return <div className="report-export">
    <ActionGroup>
      <Button ref={button} variant="secondary" loading={loading} disabled={remaining > 0} onClick={descargar} aria-describedby="report-pdf-hint">Descargar PDF</Button>
      {loading && <Button variant="quiet" onClick={() => {
        limpiar(); restoreFocus.current = true; setLoading(false); setMessage('Espera cancelada. No se descargará esta respuesta.');
      }}>Cancelar espera</Button>}
    </ActionGroup>
    <p id="report-pdf-hint">Se exportan los filtros de la última consulta aplicada, no el borrador ni solo la página visible. El PDF procede de una nueva lectura del servidor y puede diferir de la consulta previa. Máximo 500 filas por listado, sin exportación parcial; cantidades conserva su agregado completo.</p>
    <p aria-live="polite">{remaining ? `Espera ${remaining} s antes de volver a descargar.` : message}</p>
    {error && <p role="alert">{error}</p>}
  </div>;
}
