import { useEffect, useRef, useState } from 'react';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import { escucharCambioSesion, obtenerEpocaSesion } from '../../../api/api';
import { descargarRecibo } from '../services/pago.service';

export default function DescargarRecibo({ id, rol, id_paciente, recibo }) {
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  const operation = useRef(null), urls = useRef(new Set());
  useEffect(() => {
    const clear = () => {
      operation.current?.abort(); operation.current = null;
      for (const url of urls.current) URL.revokeObjectURL(url);
      urls.current.clear(); setBusy(false); setError('');
    };
    const off = escucharCambioSesion(clear); window.addEventListener('pagehide', clear);
    return () => { off(); window.removeEventListener('pagehide', clear); clear(); };
  }, [id, rol, id_paciente, recibo.numero]);
  async function descargar() {
    if (operation.current) return;
    const controller = new AbortController(); operation.current = controller;
    setBusy(true); setError('');
    try {
      const { blob, nombre, epoch } = await descargarRecibo({ id, rol, id_paciente, numero: recibo.numero }, controller.signal);
      if (controller.signal.aborted || epoch !== obtenerEpocaSesion()) return;
      const url = URL.createObjectURL(blob); urls.current.add(url);
      const a = document.createElement('a'); a.href = url; a.download = nombre;
      document.body.append(a); a.click(); a.remove();
      setTimeout(() => { URL.revokeObjectURL(url); urls.current.delete(url); }, 1000);
    } catch {
      if (!controller.signal.aborted) setError('No se pudo descargar el recibo académico. Comprueba tu sesión y vuelve a consultar la obligación. No se ha iniciado otro pago.');
    } finally {
      if (operation.current === controller) { operation.current = null; setBusy(false); }
    }
  }
  return <div className="payment-receipt"><p>Recibo académico: <span className="payment-reference">{recibo.numero}</span>.</p>
    <Button variant="secondary" disabled={busy} onClick={descargar}>{busy ? 'Descargando recibo…' : 'Descargar recibo académico en PDF'}</Button>
    {busy && <p role="status">Preparando descarga autorizada…</p>}
    {error && <Alert><p>{error}</p></Alert>}
  </div>;
}
