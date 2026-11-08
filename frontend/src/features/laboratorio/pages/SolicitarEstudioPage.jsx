import { useEffect, useRef, useState } from 'react';
import { Link, useBlocker, useLocation, useParams } from 'react-router-dom';
import { clinicalPresentation } from '../../../constants/clinicalPresentation';
import ClinicalModeError from '../../historia-clinica/components/ClinicalModeError';
import SeleccionCitaClinica from '../../historia-clinica/components/SeleccionCitaClinica';
import { useAuth } from '../../../hooks/useAuth';
import PageHeading from '../../../components/PageHeading';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import QueryMessage from '../../../components/QueryMessage';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { consultarContextoSolicitud } from '../services/laboratorio.service';
import { useLaboratorioQuery } from '../hooks/useLaboratorioQuery';
import { useSolicitudEstudio } from '../hooks/useSolicitudEstudio';
import LaboratorioQuery from '../components/LaboratorioQuery';
import SolicitudEstudioForm from '../components/SolicitudEstudioForm';
import SolicitudesTable from '../components/SolicitudesTable';
import '../styles/laboratorio.css';
import '../styles/lab-workflow.css';

export default function SolicitarEstudioPage({ seleccionar = false }) {
  const { id } = useParams(), { usuario } = useAuth();
  const location = useLocation(), entry = clinicalPresentation(location.pathname, location.search, usuario.rol);
  if (seleccionar) return entry.valid ? <SeleccionCitaClinica recorrido="solicitar-estudio" /> : <ClinicalModeError to="/laboratorio/solicitar" />;
  if (!esIdentificador(id)) return <section className="workspace-page"><h1>Consulta no disponible</h1><Link to="/laboratorio/solicitar">Volver a solicitar estudios</Link></section>;
  return <Solicitud key={`${usuario.id_usuario}:${usuario.rol}:${id}`} id={id} usuario={usuario} />;
}

const impedimentos = { LAB_EDICION_OBSOLETA: 'No se pudo comprobar la versión vigente de la consulta.', LAB_ESTADO_NO_PERMITIDO: 'La atención no admite solicitar estudios en este momento, según el servidor.' };
function Solicitud({ id, usuario }) {
  const location = useLocation(), entry = clinicalPresentation(location.pathname, location.search, usuario.rol);
  const visited = useRef(entry.valid); if (entry.valid) visited.current = true;
  const query = useLaboratorioQuery(consultarContextoSolicitud, { id, rol: usuario.rol });
  const escritura = useSolicitudEstudio(id, usuario.rol, usuario.id_usuario);
  const [dirty, setDirty] = useState(false), heading = useRef(null);
  const riesgo = ['enviando', 'incierto', 'consultando'].includes(escritura.status), busy = ['enviando', 'consultando'].includes(escritura.status);
  const blocker = useBlocker(dirty || riesgo);
  const conserva = blocker.location?.pathname === location.pathname;
  useEffect(() => { if (!dirty && !riesgo && blocker.state === 'blocked') blocker.reset(); }, [dirty, riesgo, blocker]);
  useEffect(() => {
    if (!dirty && !riesgo) return;
    const warn = e => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, riesgo]);
  useEffect(() => {
    if (!['inicial', 'enviando', 'validacion'].includes(escritura.status)) setDirty(false);
    if (escritura.status !== 'inicial') heading.current?.focus();
  }, [escritura.status]);
  const refrescar = () => { setDirty(false); escritura.reset(); query.reload(); };
  return <section className="workspace-page lab-page lab-flow lab-order-page">
    <Link className="back-link" to={`/citas/${id}/consulta?recorrido=solicitar-estudio`}>Volver a consulta</Link>
    {!entry.valid && <ClinicalModeError to="/laboratorio/solicitar" />}
    <PageHeading title="Solicitar estudio de laboratorio" description="Comprueba paciente, consulta y Médico responsable antes de enviar." />
    {blocker.state === 'blocked' && <Alert variant="warning"><p>{conserva ? 'Cambiar la presentación conserva esta solicitud solo en memoria; no inicia otra operación.' : riesgo ? 'La solicitud podría haberse registrado. Salir no revierte la operación.' : 'Si sales, se perderá el formulario que no guardaste.'}</p>
      <Button variant="secondary" onClick={() => blocker.reset()}>Permanecer</Button><Button variant="quiet" onClick={() => blocker.proceed()}>{conserva ? 'Continuar conservando el proceso' : 'Salir sin conservar el formulario'}</Button></Alert>}
    {visited.current && <div hidden={!entry.valid} inert={!entry.valid || undefined}>
    {escritura.status !== 'inicial' && <section className="lab-order-outcome" aria-label="Resultado de la solicitud" aria-live="polite" aria-busy={busy}>
      <h2 ref={heading} tabIndex={-1}>Resultado de la solicitud</h2>
      {busy && <QueryMessage loading title={escritura.status === 'enviando' ? 'Registrando solicitud…' : 'Consultando solicitudes actuales…'} />}
      {escritura.failure && <Alert variant={escritura.status === 'incierto' ? 'warning' : 'error'}><p>{escritura.failure.message}</p></Alert>}
      {escritura.status === 'registrada' && <><Alert variant="success"><p>Solicitud registrada en estado Pendiente. El estado de la cita no se modificó.</p></Alert>
        <p>{escritura.resultado.estudio_solicitado}</p><p><Link to={`/laboratorio/solicitudes/${escritura.resultado.id_solicitud}?operacion=consulta`}>Ver solicitud registrada</Link></p>
        <Link to={`/laboratorio/consultas/${escritura.resultado.id_consulta}`}>Consultar estudios actualizados de esta consulta</Link></>}
      {escritura.status === 'incierto' && <><p>No se reenviará automáticamente. No identifiques un envío anterior solo por el nombre o la fecha del estudio.</p>
        <Button disabled={escritura.remaining > 0} onClick={escritura.consultar}>Consultar solicitudes actuales</Button></>}
      {escritura.status === 'consultada' && <><Alert variant="warning"><p>Estado actual consultado. Esta lista no demuestra qué petición guardó cada estudio ni garantiza que el envío anterior haya terminado. Otra solicitud podría duplicarlo.</p></Alert>
        {escritura.resultado.solicitudes.length ? <SolicitudesTable solicitudes={escritura.resultado.solicitudes} /> : <p>No hay solicitudes visibles en esta página. Esto no demuestra que el envío anterior haya fallado.</p>}
        <p>Se muestra la primera página de solicitudes actuales.</p><Link to={`/laboratorio/consultas/${escritura.resultado.id_consulta}`}>Consultar todos los estudios de esta consulta</Link></>}
      {(['registrada', 'consultada'].includes(escritura.status) || (escritura.status === 'error' && ![401, 403, 404].includes(escritura.failure?.status))) && <p><Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Consultar contexto y preparar otra solicitud</Button></p>}
      {escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de continuar.</p>}
    </section>}
    {['inicial', 'enviando', 'validacion'].includes(escritura.status) && <LaboratorioQuery query={query}>{data => <>
      <div className="lab-context"><h2>{data.paciente.nombres} {data.paciente.apellidos}</h2><p>{data.paciente.codigo_paciente}</p>
        <dl className="lab-data"><div><dt>Consulta registrada</dt><dd>{data.codigo_consulta}</dd></div><div><dt>Médico responsable</dt><dd>{data.medico.nombres} {data.medico.apellidos}</dd></div>
          <div><dt>Cita</dt><dd>{data.codigo_cita} — {data.estado_cita}</dd></div><div><dt>Inicio programado</dt><dd><time dateTime={data.inicio_at}>{formatearInstanteCita(data.inicio_at)}</time></dd></div></dl></div>
      {!data.edicion.acciones.solicitar ? <><Alert><ul>{data.edicion.impedimentos.map(code => <li key={code}>{impedimentos[code]}</li>)}</ul></Alert><Button variant="secondary" onClick={query.reload}>Volver a consultar contexto</Button></>
        : <SolicitudEstudioForm key={`${data.epocaSesion}:${data.edicion.originales.id_version_consulta}`} contexto={data} ejecutor={usuario.nombre_visible}
          busy={busy} failure={escritura.status === 'validacion' ? escritura.failure : null} onGuardar={escritura.guardar} onDirtyChange={setDirty} />}
    </>}</LaboratorioQuery>}
    </div>}
  </section>;
}
