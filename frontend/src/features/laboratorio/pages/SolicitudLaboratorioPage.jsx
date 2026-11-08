import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { clinicalPresentation, LAB_OPERATIONS } from '../../../constants/clinicalPresentation';
import ClinicalModeError from '../../historia-clinica/components/ClinicalModeError';
import Alert from '../../../components/Alert';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import PageHeading from '../../../components/PageHeading';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { consultarSolicitud } from '../services/laboratorio.service';
import { useLaboratorioQuery } from '../hooks/useLaboratorioQuery';
import LaboratorioQuery from '../components/LaboratorioQuery';
import DescargarResultado from '../components/DescargarResultado';
import VersionesResultado from '../components/VersionesResultado';
import GestionDocumentoLaboratorio from '../components/GestionDocumentoLaboratorio';
import GestionRevisionResultado from '../components/GestionRevisionResultado';
import Button from '../../../components/Button';
import '../styles/laboratorio.css';
import '../styles/lab-reading.css';

const Fecha = ({ value }) => <time dateTime={value}>{formatearInstanteCita(value)}</time>;
export default function SolicitudLaboratorioPage() {
  const { id } = useParams(), { usuario } = useAuth();
  if (!esIdentificador(id)) return <section className="workspace-page"><h1>Solicitud no disponible</h1><Link to="/laboratorio">Volver a estudios</Link></section>;
  return <Detalle key={`${usuario.id_usuario}:${usuario.rol}:${id}`} id={id} rol={usuario.rol} ejecutor={usuario.nombre_visible} idActor={usuario.id_usuario} />;
}
function Detalle({ id, rol, ejecutor, idActor }) {
  const location = useLocation(), entry = clinicalPresentation(location.pathname, location.search, rol);
  const [retirar, setRetirar] = useState(false);
  const [legacyMode, setLegacyMode] = useState(rol === ROLES.ADMINISTRADOR ? 'documentos' : null);
  const requested = entry.valid ? entry.value ?? legacyMode : null;
  // Solo un escritor montado. Un ciclo protegido permanece vivo y apartado al cambiar
  // presentación; no se abre otro escritor hasta resolverlo en su operación original.
  const [modo, setModo] = useState(requested), [bloqueado, setBloqueado] = useState(false);
  const suspended = modo !== requested || !entry.valid;
  useEffect(() => { if (!bloqueado && modo !== requested) { setModo(requested); setRetirar(false); if (requested === 'documentos' || requested === 'revision') query.reload(); } }, [bloqueado, modo, requested]);
  const query = useLaboratorioQuery(consultarSolicitud, { id, rol, ...(rol === ROLES.ADMINISTRADOR ? { paraDocumentar: true, paraRevisar: true } : rol === ROLES.MEDICO ? { paraRevisar: true } : {}) });
  const cerrarRevision = () => { if (!entry.value) setLegacyMode(rol === ROLES.ADMINISTRADOR ? 'documentos' : null); setRetirar(false); setBloqueado(false); query.reload(); };
  return <section className="workspace-page lab-page lab-reading">
    <Link className="back-link" to={`/laboratorio${entry.valid ? entry.search : ''}`}>Volver a estudios</Link>
    {!entry.valid && <ClinicalModeError to="/laboratorio" />}
    {suspended && bloqueado && <Alert variant="warning"><p>Hay un proceso de {LAB_OPERATIONS[modo]?.label.toLowerCase()} conservado para esta solicitud. Resuélvelo antes de preparar otra operación. No se repetirá ningún envío.</p><Link to={`${location.pathname}?operacion=${modo}`}>Volver al proceso conservado</Link></Alert>}
    {entry.valid && <PageHeading title={entry.value ? entry.label : query.data?.estudio_solicitado ?? 'Solicitud de laboratorio'} description="Solicitud de laboratorio. Horarios en America/La_Paz." />}
    <div hidden={!entry.valid || retirar} inert={!entry.valid || retirar || undefined}>
    {entry.valid && <>
    {!retirar && <LaboratorioQuery query={query}>{d => {
      const documento = rol === ROLES.PACIENTE ? d.resultado : d.documento;
      return <div className="lab-reading-sheet">
        <div className="lab-context">{entry.value && <h2>{d.estudio_solicitado}</h2>}<p className="lab-reading-state">Estado del estudio <span className="role-badge" data-estado={d.estado}>{d.estado}</span></p><dl className="lab-data">
          <div><dt>Paciente</dt><dd>{d.paciente.nombres} {d.paciente.apellidos}<span className="lab-note">{d.paciente.codigo_paciente}</span></dd></div>
          <div><dt>Médico responsable</dt><dd>{d.medico.nombres} {d.medico.apellidos}</dd></div>
          <div><dt>Solicitud</dt><dd><Fecha value={d.solicitada_en} /></dd></div>
          {rol !== ROLES.PACIENTE && <div><dt>Usuario que solicitó el estudio</dt><dd>{d.creada_por === null ? 'Autor no acreditado en el registro anterior' : `Usuario ${d.creada_por}`}</dd></div>}
        </dl><Link to={`/laboratorio/consultas/${d.id_consulta}${entry.search}`}>Ver estudios de esta consulta</Link>
        {rol === ROLES.ADMINISTRADOR && <p><Link to={`/laboratorio/pacientes/${d.paciente.id_paciente}${entry.search}`}>Ver estudios de este paciente</Link></p>}</div>
        <section className="lab-document" aria-labelledby="lab-document-title"><div><h2 id="lab-document-title">{rol === ROLES.PACIENTE ? 'Resultado' : 'Documento vigente'}</h2>
          {documento ? <><p>Versión {documento.numero_version}</p>{d.resultado && <><p>Publicado: <Fecha value={d.resultado.publicado_en} /></p><p>{d.resultado.tipo_mime === 'application/pdf' ? 'PDF' : 'Imagen'} · {Math.ceil(d.resultado.tamano_bytes / 1024)} KB</p></>}</> : <p>{rol === ROLES.PACIENTE ? 'Tu resultado aún no está disponible. Vuelve a consultar más tarde.' : 'No hay un documento vigente disponible para descargar.'}</p>}
        </div>{documento && <DescargarResultado key={documento.id_version_resultado} solicitud={id} version={documento.id_version_resultado} resultado={d.resultado} onReload={query.reload} />}</section>
        {rol !== ROLES.PACIENTE && d.ultima_revision && <section className="lab-section" aria-labelledby="lab-review-title"><h2 id="lab-review-title">Última revisión del documento vigente</h2>
          <dl className="lab-data"><div><dt>Decisión</dt><dd>{d.ultima_revision.decision === 'Autorizar' ? 'Publicación autorizada' : 'Devuelto para corrección'}</dd></div>
            <div><dt>Fecha de revisión</dt><dd><Fecha value={d.ultima_revision.revisada_en} /></dd></div>
            <div><dt>Usuario revisor</dt><dd>{d.ultima_revision.revisada_por === null ? 'Autor no acreditado en el registro anterior' : `Usuario ${d.ultima_revision.revisada_por}`}</dd></div>
            {d.ultima_revision.motivo && <div className="lab-wide"><dt>Motivo</dt><dd className="lab-text">{d.ultima_revision.motivo}</dd></div>}
          </dl></section>}
        {rol !== ROLES.PACIENTE && <VersionesResultado key={`${id}:${d.documento?.id_version_resultado ?? 'sin-documento'}`} id={id} rol={rol} />}
      </div>;
    }}</LaboratorioQuery>}
    {!entry.value && modo !== 'revision' && query.data?.revision?.acciones.revisar && <p className="lab-workflow-switch"><Button disabled={bloqueado || query.loading || Boolean(query.failure)} onClick={() => { setLegacyMode('revision'); setRetirar(false); query.reload(); }}>Revisar resultado</Button></p>}
    </>}
    </div>
    {entry.valid && entry.value && !suspended && !query.loading && !query.failure && ((entry.value === 'documentos' && !query.data?.edicion) || (entry.value === 'revision' && !query.data?.revision)) && <p>No hay contexto autorizado para esta operación.</p>}
    {modo === 'revision' && <>{!entry.value && <p className="lab-workflow-switch"><Button variant="quiet" disabled={bloqueado} onClick={cerrarRevision}>{rol === ROLES.ADMINISTRADOR ? 'Volver a documentación del estudio' : 'Volver a lectura del resultado'}</Button></p>}
      <GestionRevisionResultado suspended={suspended} id={id} rol={rol} idActor={idActor} query={query} ejecutor={ejecutor} onRetirarDetalle={setRetirar} onBloquearCambio={setBloqueado} onCerrar={cerrarRevision} /></>}
    {rol === ROLES.ADMINISTRADOR && modo === 'documentos' && <GestionDocumentoLaboratorio suspended={suspended} id={id} query={query} ejecutor={ejecutor} onRetirarDetalle={setRetirar} onBloquearCambio={setBloqueado} />}
  </section>;
}
