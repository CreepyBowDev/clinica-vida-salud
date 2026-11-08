import { useEffect, useRef, useState } from 'react';
import { useBlocker, useLocation } from 'react-router-dom';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import QueryMessage from '../../../components/QueryMessage';
import { useRevisionResultado } from '../hooks/useRevisionResultado';
import RevisionResultadoForm from './RevisionResultadoForm';
import '../styles/lab-review-editor.css';

export default function GestionRevisionResultado({ id, rol, idActor, query, ejecutor, onRetirarDetalle, onBloquearCambio, onCerrar, suspended = false }) {
  const escritura = useRevisionResultado(id, rol, idActor), [dirty, setDirty] = useState(false), heading = useRef(null);
  const riesgo = ['enviando', 'incierto', 'consultando'].includes(escritura.status), busy = ['enviando', 'consultando'].includes(escritura.status), contexto = query.data;
  const blocker = useBlocker(dirty || riesgo || escritura.remaining > 0), location = useLocation();
  const conserva = blocker.location?.pathname === location.pathname;
  useEffect(() => { onRetirarDetalle(['enviando', 'incierto', 'consultando', 'error'].includes(escritura.status)); }, [escritura.status, onRetirarDetalle]);
  useEffect(() => { onBloquearCambio(dirty || riesgo || escritura.remaining > 0); }, [dirty, riesgo, escritura.remaining, onBloquearCambio]);
  useEffect(() => () => onBloquearCambio(false), [onBloquearCambio]);
  useEffect(() => { if (!dirty && !riesgo && escritura.remaining === 0 && blocker.state === 'blocked') blocker.reset(); }, [dirty, riesgo, escritura.remaining, blocker]);
  useEffect(() => {
    if (!dirty && !riesgo) return;
    const warn = e => { e.preventDefault(); e.returnValue = ''; }; window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, riesgo]);
  useEffect(() => {
    if (!['inicial', 'enviando', 'validacion'].includes(escritura.status)) setDirty(false);
    if (escritura.status !== 'inicial' && !suspended) heading.current?.focus();
  }, [escritura.status, suspended]);
  useEffect(() => { if (query.loading || query.failure) setDirty(false); }, [query.loading, query.failure]);
  const refrescar = () => { setDirty(false); escritura.reset(); query.reload(); };
  async function guardar(entrada) { if (await escritura.guardar(entrada)) query.reload(); }
  async function recuperar() { if (await escritura.consultar()) query.reload(); }
  return <section className="lab-section lab-review-workspace" aria-label="Revisión y publicación del resultado">
    {blocker.state === 'blocked' && <Alert variant="warning"><p>{conserva ? 'Cambiar de presentación conserva la revisión solo en memoria. Debes volver a este proceso para resolverlo.' : riesgo ? 'La revisión podría haberse guardado. Salir no revierte una publicación o devolución.' : 'Si sales, se perderán la decisión y el motivo que no guardaste.'}</p><Button variant="secondary" onClick={() => blocker.reset()}>Permanecer</Button><Button variant="quiet" onClick={() => blocker.proceed()}>{conserva ? 'Continuar conservando el proceso' : 'Salir sin conservar la revisión'}</Button></Alert>}
    <div hidden={suspended} inert={suspended || undefined}><fieldset disabled={suspended} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
    {escritura.status !== 'inicial' && <section aria-live="polite" aria-label="Resultado de la revisión"><h2 ref={heading} tabIndex={-1}>Resultado de la revisión</h2>
      {busy && <QueryMessage loading title={escritura.status === 'enviando' ? 'Guardando revisión…' : 'Consultando estado actual…'} />}
      {escritura.failure && <Alert variant={escritura.status === 'incierto' ? 'warning' : 'error'}><p>{escritura.failure.message}</p></Alert>}
      {escritura.status === 'guardada' && <Alert variant="success"><p>{escritura.resultado.ultima_revision.decision === 'Autorizar' ? 'Publicación autorizada. El resultado vigente está Disponible para el Paciente titular.' : 'Documento devuelto para corrección. Permanece En revisión y no está publicado.'}</p></Alert>}
      {escritura.status === 'consultada' && <Alert variant="warning"><p>Estado actual consultado. No demuestra qué petición guardó la decisión ni garantiza que el envío anterior haya terminado. No se repetirá automáticamente.</p></Alert>}
      {escritura.resultado && <p>Estado actual: {escritura.resultado.estado}. {escritura.resultado.documento ? `Versión vigente ${escritura.resultado.documento.numero_version}.` : 'Sin documento vigente.'}</p>}
      {escritura.status === 'incierto' && <Button disabled={escritura.remaining > 0} onClick={recuperar}>Consultar estado de la revisión</Button>}
      {(['guardada', 'consultada'].includes(escritura.status) || (escritura.status === 'error' && ![401, 403, 404].includes(escritura.failure?.status))) && <Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Consultar contexto y preparar nueva decisión</Button>}
      {escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de continuar.</p>}
    </section>}
    {['inicial', 'enviando', 'validacion'].includes(escritura.status) && contexto?.revision && !query.loading && !query.failure && <>
      {!contexto.revision.acciones.revisar ? <><Alert variant="warning"><p>Esta versión no admite revisión en su estado actual. Si fue devuelta, primero debe corregirse el documento; si está Disponible, no se vuelve a revisar por este flujo.</p></Alert><Button variant="secondary" onClick={query.reload}>Volver a consultar contexto de revisión</Button></>
        : <RevisionResultadoForm key={`${contexto.epocaSesion}:${JSON.stringify(contexto.revision.originales)}`} contexto={contexto} ejecutor={ejecutor} busy={busy} failure={escritura.status === 'validacion' ? escritura.failure : null}
          onGuardar={guardar} onDirtyChange={setDirty} onCancel={() => { setDirty(false); onCerrar(); }} />}
    </>}
    </fieldset></div>
  </section>;
}
