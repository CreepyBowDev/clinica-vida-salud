import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
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

const Fecha = ({ value }) => <time dateTime={value}>{formatearInstanteCita(value)}</time>;
export default function SolicitudLaboratorioPage() {
  const { id } = useParams(), { usuario } = useAuth();
  if (!esIdentificador(id)) return <section className="workspace-page"><h1>Solicitud no disponible</h1><Link to="/laboratorio">Volver a estudios</Link></section>;
  return <Detalle key={`${usuario.id_usuario}:${usuario.rol}:${id}`} id={id} rol={usuario.rol} ejecutor={usuario.nombre_visible} idActor={usuario.id_usuario} />;
}
function Detalle({ id, rol, ejecutor, idActor }) {
  const [retirar, setRetirar] = useState(false);
  const [modo, setModo] = useState(rol === ROLES.ADMINISTRADOR ? 'documentos' : null), [bloqueado, setBloqueado] = useState(false);
  const query = useLaboratorioQuery(consultarSolicitud, { id, rol, ...(rol === ROLES.ADMINISTRADOR ? { paraDocumentar: true, paraRevisar: true } : rol === ROLES.MEDICO ? { paraRevisar: true } : {}) });
  const cerrarRevision = () => { setModo(rol === ROLES.ADMINISTRADOR ? 'documentos' : null); setRetirar(false); setBloqueado(false); query.reload(); };
  return <section className="workspace-page lab-page">
    <Link className="back-link" to="/laboratorio">Volver a estudios</Link>
    <PageHeading title={query.data?.estudio_solicitado ?? 'Solicitud de laboratorio'} description="Solicitud de laboratorio. Horarios en America/La_Paz." />
    {!retirar && <LaboratorioQuery query={query}>{d => {
      const documento = rol === ROLES.PACIENTE ? d.resultado : d.documento;
      return <>
        <div className="lab-context"><span className="role-badge">{d.estado}</span><dl className="lab-data">
          <div><dt>Paciente</dt><dd>{d.paciente.nombres} {d.paciente.apellidos}<span className="lab-note">{d.paciente.codigo_paciente}</span></dd></div>
          <div><dt>Médico responsable</dt><dd>{d.medico.nombres} {d.medico.apellidos}</dd></div>
          <div><dt>Solicitud</dt><dd><Fecha value={d.solicitada_en} /></dd></div>
          {rol !== ROLES.PACIENTE && <div><dt>Usuario que solicitó el estudio</dt><dd>{d.creada_por === null ? 'Autor no acreditado en el registro anterior' : `Usuario ${d.creada_por}`}</dd></div>}
        </dl><Link to={`/laboratorio/consultas/${d.id_consulta}`}>Ver estudios de esta consulta</Link>
        {rol === ROLES.ADMINISTRADOR && <p><Link to={`/laboratorio/pacientes/${d.paciente.id_paciente}`}>Ver estudios de este paciente</Link></p>}</div>
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
      </>;
    }}</LaboratorioQuery>}
    {modo !== 'revision' && query.data?.revision?.acciones.revisar && <p className="lab-workflow-switch"><Button disabled={bloqueado || query.loading || Boolean(query.failure)} onClick={() => { setModo('revision'); setRetirar(false); query.reload(); }}>Revisar resultado</Button></p>}
    {modo === 'revision' && <><p className="lab-workflow-switch"><Button variant="quiet" disabled={bloqueado} onClick={cerrarRevision}>{rol === ROLES.ADMINISTRADOR ? 'Volver a documentación del estudio' : 'Volver a lectura del resultado'}</Button></p>
      <GestionRevisionResultado id={id} rol={rol} idActor={idActor} query={query} ejecutor={ejecutor} onRetirarDetalle={setRetirar} onBloquearCambio={setBloqueado} onCerrar={cerrarRevision} /></>}
    {rol === ROLES.ADMINISTRADOR && modo === 'documentos' && <GestionDocumentoLaboratorio id={id} query={query} ejecutor={ejecutor} onRetirarDetalle={setRetirar} onBloquearCambio={setBloqueado} />}
  </section>;
}
