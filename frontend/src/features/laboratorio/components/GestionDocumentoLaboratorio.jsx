import { useEffect, useRef, useState } from 'react';
import { Link, useBlocker, useLocation } from 'react-router-dom';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import QueryMessage from '../../../components/QueryMessage';
import { ROLES } from '../../../constants/roles';
import { useDocumentoLaboratorio } from '../hooks/useDocumentoLaboratorio';
import { ACCIONES_DOCUMENTO } from '../services/documentoLaboratorio.service';
import DocumentoLaboratorioForm from './DocumentoLaboratorioForm';
import '../styles/lab-document-editor.css';

export default function GestionDocumentoLaboratorio({ id, query, ejecutor, onRetirarDetalle, onBloquearCambio, suspended = false }) {
  const escritura = useDocumentoLaboratorio(id), [tipo, setTipo] = useState(null), [dirty, setDirty] = useState(false), heading = useRef(null);
  const riesgo = ['enviando', 'incierto', 'consultando'].includes(escritura.status), busy = ['enviando', 'consultando'].includes(escritura.status);
  const blocker = useBlocker(dirty || riesgo || escritura.remaining > 0), contexto = query.data, location = useLocation();
  const conserva = blocker.location?.pathname === location.pathname;
  useEffect(() => { onRetirarDetalle(['enviando', 'incierto', 'consultando', 'error'].includes(escritura.status)); }, [escritura.status, onRetirarDetalle]);
  useEffect(() => { onBloquearCambio?.(dirty || riesgo || escritura.remaining > 0); }, [dirty, riesgo, escritura.remaining, onBloquearCambio]);
  useEffect(() => () => onBloquearCambio?.(false), [onBloquearCambio]);
  useEffect(() => { if (!dirty && !riesgo && escritura.remaining === 0 && blocker.state === 'blocked') blocker.reset(); }, [dirty, riesgo, escritura.remaining, blocker]);
  useEffect(() => {
    if (!dirty && !riesgo) return;
    const warn = e => { e.preventDefault(); e.returnValue = ''; }; window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, riesgo]);
  useEffect(() => {
    if (!['inicial', 'enviando', 'validacion'].includes(escritura.status)) { setDirty(false); setTipo(null); }
    if (escritura.status !== 'inicial' && !suspended) heading.current?.focus();
  }, [escritura.status, suspended]);
  const refrescar = () => { setDirty(false); setTipo(null); escritura.reset(); query.reload(); };
  async function guardar(entrada) { if (await escritura.guardar({ ...entrada, rol: ROLES.ADMINISTRADOR })) query.reload(); }
  async function recuperar() { if (await escritura.consultar()) query.reload(); }
  return <section className="lab-section lab-document-workspace" aria-label="Gestión documental administrativa">
    {blocker.state === 'blocked' && <Alert variant="warning"><p>{conserva ? 'Cambiar de presentación conserva el proceso documental y el archivo solo en memoria. Debes volver a este proceso para resolverlo.' : riesgo ? 'El documento o la asociación podrían haberse guardado. Salir no revierte la operación.' : 'Si sales, se perderá el formulario y el archivo seleccionado.'}</p><Button variant="secondary" onClick={() => blocker.reset()}>Permanecer</Button><Button variant="quiet" onClick={() => blocker.proceed()}>{conserva ? 'Continuar conservando el proceso' : 'Salir sin conservar el formulario'}</Button></Alert>}
    <div hidden={suspended} inert={suspended || undefined}><fieldset disabled={suspended} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
    {escritura.status !== 'inicial' && <section aria-live="polite" aria-label="Resultado de la operación documental"><h2 ref={heading} tabIndex={-1}>Resultado de la operación documental</h2>
      {busy && <QueryMessage loading title={escritura.status === 'enviando' ? 'Guardando documento…' : 'Consultando estado actual…'} />}
      {escritura.failure && <Alert variant={escritura.status === 'incierto' ? 'warning' : 'error'}><p>{escritura.failure.message}</p></Alert>}
      {escritura.status === 'guardada' && <Alert variant="success"><p>Operación documental guardada. Requiere revisión y no publica resultados.</p></Alert>}
      {escritura.status === 'consultada' && <Alert variant="warning"><p>Estado actual consultado. No acredita qué petición guardó estos datos ni garantiza que el envío anterior haya terminado. Un nuevo envío podría duplicar la operación.</p></Alert>}
      {escritura.resultado && <><p>Origen: {escritura.resultado.origen.estado}. {escritura.resultado.origen.documento ? `Versión vigente ${escritura.resultado.origen.documento.numero_version}.` : 'Sin documento vigente.'}</p>
        {escritura.resultado.destino && <p>Destino: {escritura.resultado.destino.paciente.nombres} {escritura.resultado.destino.paciente.apellidos}, {escritura.resultado.destino.estudio_solicitado}, {escritura.resultado.destino.estado}. <Link to={`/laboratorio/solicitudes/${escritura.resultado.destino.id_solicitud}?operacion=documentos`}>Consultar destino actualizado</Link></p>}</>}
      {escritura.status === 'incierto' && <><p>No se reenviará automáticamente. Consulta ambos extremos si la operación corregía una asociación.</p><Button disabled={escritura.remaining > 0} onClick={recuperar}>Consultar estado documental actual</Button></>}
      {(['guardada', 'consultada'].includes(escritura.status) || (escritura.status === 'error' && ![401, 403, 404].includes(escritura.failure?.status))) && <Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Consultar contexto y preparar otra operación</Button>}
      {escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de continuar.</p>}
    </section>}
    {['inicial', 'enviando', 'validacion'].includes(escritura.status) && contexto?.edicion && !query.loading && !query.failure && <>
      {!tipo ? <><h2>Documentación del estudio</h2><p>Carga o corrige el documento externo según las acciones autorizadas. La revisión y publicación son operaciones separadas.</p>
        <div className="action-group">{Object.entries(ACCIONES_DOCUMENTO).filter(([k]) => contexto.edicion.acciones[k === 'sustituir' ? 'corregir' : k]).map(([k, label]) => <Button key={k} variant="secondary" onClick={() => { setTipo(k); setDirty(true); }}>{label}</Button>)}</div>
        {!Object.values(contexto.edicion.acciones).some(Boolean) && <p>La solicitud no admite cambios documentales en su estado actual.</p>}</>
        : <DocumentoLaboratorioForm key={`${contexto.epocaSesion}:${JSON.stringify(contexto.edicion.originales)}:${tipo}`} contexto={contexto} tipo={tipo} busy={busy} ejecutor={ejecutor} failure={escritura.status === 'validacion' ? escritura.failure : null} onGuardar={guardar} onDirtyChange={setDirty} onCancel={refrescar} />}
    </>}
    </fieldset></div>
  </section>;
}
