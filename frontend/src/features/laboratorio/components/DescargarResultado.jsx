import { useEffect, useRef, useState } from 'react';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import { escucharCambioSesion, obtenerEpocaSesion } from '../../../api/api';
import { obtenerArchivo } from '../services/laboratorio.service';
import { iniciarDescarga } from '../utils/descargaLaboratorio';
import { laboratorioError } from '../utils/laboratorioError';

export default function DescargarResultado({ solicitud, version, resultado, onReload }) {
  const [state, setState] = useState(null), [clock, setClock] = useState(Date.now());
  const operation = useRef(null), release = useRef(null), until = useRef(0), blocked = useRef(false);
  useEffect(() => {
    blocked.current = false;
    const clear = () => { blocked.current = true; operation.current?.abort(); operation.current = null; release.current?.(); release.current = null; setState(null); };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    const timer = setInterval(() => setClock(Date.now()), 1000);
    return () => { unsubscribe(); window.removeEventListener('pagehide', clear); clearInterval(timer); clear(); };
  }, []);
  const remaining = Math.max(0, Math.ceil((until.current - clock) / 1000));
  async function download() {
    if (blocked.current || operation.current || until.current > Date.now() || state?.failure) return;
    const controller = new AbortController(), epoca = obtenerEpocaSesion(); operation.current = controller;
    setState({ busy: true }); release.current?.(); release.current = null;
    try {
      const file = await obtenerArchivo({ solicitud, version, resultado }, controller.signal);
      if (controller.signal.aborted || epoca !== obtenerEpocaSesion() || blocked.current) return;
      release.current = iniciarDescarga(file); setState({ done: true });
    } catch (error) {
      if (controller.signal.aborted || epoca !== obtenerEpocaSesion() || blocked.current) return;
      const failure = laboratorioError(error); until.current = Date.now() + failure.retryAfter * 1000;
      setClock(Date.now()); setState({ failure });
    } finally { if (operation.current === controller) operation.current = null; }
  }
  return <div className="lab-download">
    <Button disabled={state?.busy || Boolean(state?.failure) || remaining > 0} onClick={download}>{state?.busy ? 'Descargando…' : 'Descargar resultado'}</Button>
    <p className="lab-note">Documento protegido. Guárdalo y ábrelo fuera de este portal.</p>
    {state?.done && <p role="status">Descarga iniciada.</p>}
    {state?.failure && <><Alert><p>{state.failure.message}</p>{remaining > 0 && <p>Espera {remaining} segundos antes de volver a consultar.</p>}</Alert>
      {![401, 403].includes(state.failure.status) && <Button variant="secondary" disabled={remaining > 0} onClick={onReload}>Volver a consultar la solicitud</Button>}</>}
  </div>;
}
