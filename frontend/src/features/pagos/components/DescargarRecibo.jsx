import { useEffect, useRef, useState } from 'react';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import { escucharCambioSesion, obtenerEpocaSesion } from '../../../api/api';
import { descargarRecibo } from '../services/pago.service';

export default function DescargarRecibo({ id, rol, id_paciente, recibo }) {
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [started, setStarted] = useState(false);
  const operation = useRef(null), urls = useRef(new Set());
  useEffect(() => {
    const clear = () => {
      operation.current?.abort(); operation.current = null;
      for (const url of urls.current) URL.revokeObjectURL(url);
      urls.current.clear(); setBusy(false); setError(''); setStarted(false);
    };
    const off = escucharCambioSesion(clear); window.addEventListener('pagehide', clear);
    return () => { off(); window.removeEventListener('pagehide', clear); clear(); };
  }, [id, rol, id_paciente, recibo.numero]);
  async function descargar() {
    if (operation.current) return;
    const controller = new AbortController(); operation.current = controller;
    setBusy(true); setError(''); setStarted(false);
    try {
      const { blob, nombre, epoch } = await descargarRecibo({ id, rol, id_paciente, numero: recibo.numero }, controller.signal);
      if (controller.signal.aborted || epoch !== obtenerEpocaSesion()) return;
      const url = URL.createObjectURL(blob); urls.current.add(url);
      const a = document.createElement('a'); a.href = url; a.download = nombre;
       document.body.append(a); a.click(); a.remove();
       setStarted(true);
      setTimeout(() => { URL.revokeObjectURL(url); urls.current.delete(url); }, 1000);
    } catch {
      if (!controller.signal.aborted) setError('No se pudo descargar el recibo académico. Comprueba tu sesión y vuelve a consultar la obligación. No se ha iniciado otro pago.');
    } finally {
      if (operation.current === controller) { operation.current = null; setBusy(false); }
    }
  }
  return <section className="payment-receipt" aria-label="Recibo original"><div className="payment-receipt-heading"><div><h3>Recibo original</h3>
    <p>Recibo académico: <span className="payment-reference">{recibo.numero}</span></p>
    <p className="payment-record-meta">PDF de pago simulado. No es una factura fiscal ni acredita una transferencia real.</p></div>
    <Button variant="secondary" disabled={busy} onClick={descargar}>{busy ? 'Descargando recibo…' : 'Descargar recibo académico en PDF'}</Button></div>
    {busy && <p role="status">Preparando descarga autorizada…</p>}
    {started && <p role="status">Se solicitó al navegador guardar el PDF. Comprueba sus descargas.</p>}
    {error && <Alert><p>{error}</p></Alert>}
    <p className="payment-record-meta">Conserva los datos del pago original. Una devolución posterior se consulta por separado.</p>
  </section>;
}
