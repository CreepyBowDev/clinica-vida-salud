import { useEffect, useRef, useState } from 'react';
import { Link, useBlocker, useLocation, useParams } from 'react-router-dom';
import { clinicalPresentation } from '../../../constants/clinicalPresentation';
import ClinicalModeError from '../components/ClinicalModeError';
import SeleccionCitaClinica from '../components/SeleccionCitaClinica';
import PageHeading from '../../../components/PageHeading';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import QueryMessage from '../../../components/QueryMessage';
import { useAuth } from '../../../hooks/useAuth';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarConsulta } from '../services/historiaClinica.service';
import { useClinicaQuery } from '../hooks/useClinicaQuery';
import { useRegistroConsulta } from '../hooks/useRegistroConsulta';
import ClinicaQuery from '../components/ClinicaQuery';
import RegistroConsultaForm from '../components/RegistroConsultaForm';
import { FechaClinica, IdentidadClinica } from '../components/ContenidoClinico';
import '../styles/clinica.css';
import '../styles/clinical-editor.css';

export default function ConsultaEditorPage({ seleccionar = false }) {
  const { id } = useParams(), { usuario } = useAuth();
  const location = useLocation(), entry = clinicalPresentation(location.pathname, location.search, usuario.rol);
  if (seleccionar) return entry.valid ? <SeleccionCitaClinica recorrido="consulta" /> : <ClinicalModeError to="/registrar-consulta" />;
  if (!esIdentificador(id)) return <section className="workspace-page"><h1>Consulta no disponible</h1><Link to="/historia-clinica">Volver a historia clínica</Link></section>;
  return <Editor key={`${usuario.id_usuario}:${usuario.rol}:${id}`} id={id} usuario={usuario} />;
}

const impedimentos = { CLINICA_EDICION_OBSOLETA: 'La información cambió o no se pudo comprobar su versión vigente.',
  CLINICA_CITA_NO_PERMITIDA: 'El estado de la cita no permite documentar esta atención.', CLINICA_ATENCION_ANTICIPADA: 'La cita aún no alcanzó su inicio según el servidor.',
  CLINICA_CONSULTA_EXISTENTE: 'La cita ya tiene una consulta. Vuelve a consultar su información vigente.', RECURSO_NO_ENCONTRADO: 'No hay una consulta vigente disponible para corregir.' };
function Editor({ id, usuario }) {
  const location = useLocation(), entry = clinicalPresentation(location.pathname, location.search, usuario.rol);
  const visited = useRef(entry.valid); if (entry.valid) visited.current = true;
  const query = useClinicaQuery(consultarConsulta, { id, rol: usuario.rol, paraRegistro: true });
  const escritura = useRegistroConsulta(id, usuario.rol, usuario.id_usuario);
  const actualizar = Boolean(query.data?.consulta);
  const [dirty, setDirty] = useState(false), heading = useRef(null);
  const riesgo = ['guardando', 'incierto', 'consultando'].includes(escritura.status), busy = ['guardando', 'consultando'].includes(escritura.status);
  const blocker = useBlocker(dirty || riesgo);
  const conserva = blocker.location?.pathname === location.pathname;
  useEffect(() => { if (!dirty && !riesgo && blocker.state === 'blocked') blocker.reset(); }, [dirty, riesgo, blocker]);
  useEffect(() => {
    if (!dirty && !riesgo) return;
    const warn = event => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, riesgo]);
  useEffect(() => {
    if (!['inicial', 'guardando', 'validacion'].includes(escritura.status)) setDirty(false);
    if (escritura.status !== 'inicial') heading.current?.focus();
  }, [escritura.status]);
  useEffect(() => {
    const hide = () => setDirty(false);
    window.addEventListener('pagehide', hide); return () => window.removeEventListener('pagehide', hide);
  }, []);
  const refrescar = () => { setDirty(false); escritura.reset(); query.reload(); };
  return <section className="workspace-page clinical-page clinical-editor-page">
    <Link className="back-link" to={`/citas/${id}/consulta?recorrido=consulta`}>Volver a consulta</Link>
    {!entry.valid && <ClinicalModeError to="/registrar-consulta" />}
    <PageHeading title={actualizar ? 'Corregir consulta médica' : 'Registrar consulta médica'} description="Documenta una atención real de esta cita. Horarios en America/La_Paz." />
    {blocker.state === 'blocked' && <Alert variant="warning"><p>{conserva ? 'Cambiar la presentación conserva este proceso clínico solo en memoria; no inicia otra operación.' : riesgo ? 'La consulta podría haberse guardado. Salir no revierte la operación.' : 'Si sales, se perderán los datos del formulario que no guardaste.'}</p>
      <Button variant="secondary" onClick={() => blocker.reset()}>Permanecer</Button><Button variant="quiet" onClick={() => blocker.proceed()}>{conserva ? 'Continuar conservando el proceso' : 'Salir sin conservar el formulario'}</Button></Alert>}
    {visited.current && <div hidden={!entry.valid} inert={!entry.valid || undefined}>
    {escritura.status !== 'inicial' && <section className="clinical-editor-outcome" aria-label="Resultado del registro" aria-live="polite" aria-busy={busy}>
      <h2 tabIndex={-1} ref={heading}>Resultado del registro</h2>
      {busy && <QueryMessage loading title={escritura.status === 'guardando' ? 'Guardando consulta…' : 'Consultando estado actual…'} />}
      {escritura.failure && <Alert><p>{escritura.failure.message}</p></Alert>}
      {escritura.status === 'guardada' && <Alert variant="success"><p>{escritura.resultado?.modificado === false ? 'No hubo cambios. El servidor validó la operación sin crear nuevas versiones clínicas.'
        : escritura.resultado?.modificado === true ? 'Corrección guardada. Se conservaron las versiones anteriores. El estado de la cita no se modificó.'
          : 'Consulta registrada. El estado de la cita no se modificó. El usuario ejecutor y el Médico responsable conservan sus identidades.'}</p></Alert>}
      {escritura.status === 'recuperada' && <Alert variant="warning"><p>Estado actual consultado. No demuestra qué petición concreta guardó los datos ni garantiza que una petición anterior haya terminado.</p></Alert>}
      {escritura.resultado && <p>{escritura.resultado.consulta ? `Consulta vigente: ${escritura.resultado.consulta.codigo_consulta}.` : 'No hay una consulta visible en este momento; esto no demuestra que el envío anterior haya fallado.'}</p>}
      {escritura.status === 'incierto' && <><p>No se reenviará automáticamente. Consulta el estado actual antes de decidir otra operación.</p><Button disabled={escritura.remaining > 0} onClick={escritura.consultar}>Consultar estado actual</Button></>}
      {escritura.status === 'error' && ![401, 403, 404].includes(escritura.failure?.status) && <Button variant="secondary" disabled={escritura.remaining > 0} onClick={refrescar}>Consultar contexto y comenzar nueva revisión</Button>}
      {['guardada', 'recuperada'].includes(escritura.status) && <Link className="button button--secondary" to={`/citas/${id}/consulta?recorrido=consulta`}>Consultar información vigente</Link>}
      {escritura.remaining > 0 && <p>Espera {escritura.remaining} segundos antes de continuar.</p>}
    </section>}
    {['inicial', 'guardando', 'validacion'].includes(escritura.status) && <ClinicaQuery query={query}>{data => <>
      <div className="clinical-editor-context"><IdentidadClinica paciente={data.paciente} />
      <p>Cita: {data.cita.codigo_cita}. Médico responsable: {data.cita.medico.nombres} {data.cita.medico.apellidos}.</p>
      <p>Inicio programado: <FechaClinica value={data.cita.inicio_at} />. Estado consultado: {data.cita.estado}.</p></div>
      {!data.edicion ? <Alert><p>No tienes contexto autorizado para registrar una consulta en esta cita. Puedes consultar su información clínica permitida.</p></Alert>
        : !data.edicion.acciones[data.consulta ? 'actualizar' : 'registrar'] ? <><Alert><ul>{data.edicion.impedimentos[data.consulta ? 'actualizar' : 'registrar'].map(code => <li key={code}>{impedimentos[code]}</li>)}</ul></Alert>
          <Button variant="secondary" onClick={query.reload}>Volver a consultar contexto</Button></>
          : <RegistroConsultaForm key={`${data.edicion.consultado_en}:${data.edicion.originales.actualizada_en}:${data.edicion.originales.id_version_consulta}:${data.edicion.originales.id_version_antecedente}`}
            contexto={data} busy={busy} failure={escritura.status === 'validacion' ? escritura.failure : null} ejecutor={usuario.nombre_visible}
            onDirtyChange={setDirty} onGuardar={escritura.guardar} />}
    </>}</ClinicaQuery>}
    </div>}
  </section>;
}
