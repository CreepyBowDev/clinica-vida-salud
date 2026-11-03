import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import PageHeading from '../../../components/PageHeading';
import ActionGroup from '../../../components/ActionGroup';
import Pagination from '../../../components/Pagination';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarConsulta } from '../services/historiaClinica.service';
import { useClinicaQuery } from '../hooks/useClinicaQuery';
import ClinicaQuery from '../components/ClinicaQuery';
import { ContenidoClinico, FechaClinica, IdentidadClinica } from '../components/ContenidoClinico';
import '../styles/clinica.css';
import '../styles/clinical-reading.css';

export default function ConsultaDetallePage() {
  const { id } = useParams(), { usuario } = useAuth();
  if (!esIdentificador(id)) return <section className="workspace-page"><h1>Consulta no disponible</h1><Link to="/historia-clinica">Volver a historia clínica</Link></section>;
  return <Detalle key={`${usuario.id_usuario}:${usuario.rol}:${id}`} id={id} rol={usuario.rol} />;
}

function Detalle({ id, rol }) {
  const [page, setPage] = useState(1), results = useRef(null);
  const query = useClinicaQuery(consultarConsulta, { id, rol, page, paraRegistro: rol !== ROLES.PACIENTE });
  return <section className="workspace-page clinical-page clinical-reading">
    <Link className="back-link" to={rol === ROLES.PACIENTE ? `/mis-citas/${id}` : `/citas/${id}`}>Volver a la cita</Link>
    <PageHeading title="Consulta médica" description="Lectura de la información vigente. Horarios en America/La_Paz." />
    <div ref={results} tabIndex={-1} className="clinical-sheet"><ClinicaQuery query={query}>{data => <>
      <IdentidadClinica paciente={data.paciente} />
      <ActionGroup className="clinical-reading-actions">
      {data.edicion?.acciones.registrar && <Link className="button button--primary" to={`/citas/${id}/consulta/editar`}>Registrar consulta médica</Link>}
      {data.edicion?.acciones.actualizar && <Link className="button button--primary" to={`/citas/${id}/consulta/editar`}>Corregir consulta y antecedentes</Link>}
      <Link to={rol === ROLES.PACIENTE ? '/historia-clinica' : `/historia-clinica/pacientes/${data.paciente.id_paciente}`}>Consultar historia clínica del paciente</Link>
      </ActionGroup>
      <section className="clinical-section clinical-appointment" aria-labelledby="clinical-appointment"><h2 id="clinical-appointment">Atención programada</h2>
        <dl className="clinical-data"><div><dt>Código de cita</dt><dd>{data.cita.codigo_cita}</dd></div>
          <div><dt>Médico responsable</dt><dd>{data.cita.medico.nombres} {data.cita.medico.apellidos}</dd></div>
          <div><dt>Fecha y hora programadas</dt><dd><FechaClinica value={data.cita.inicio_at} /></dd></div>
          <div><dt>Estado de cita</dt><dd>{data.cita.estado}</dd></div><div><dt>Consultorio</dt><dd>{data.cita.consultorio_atencion}</dd></div>
        </dl>
      </section>
      <section className="clinical-section" aria-labelledby="clinical-consultation"><h2 id="clinical-consultation">Consulta registrada</h2>
        {data.consulta === null ? <p>No hay una consulta registrada para esta cita.</p> : <>
          <p>Registro de consulta: <FechaClinica value={data.consulta.creada_en} /></p>
          <p>Hora real de inicio: {data.consulta.iniciada_en === null ? 'No registrada' : <FechaClinica value={data.consulta.iniciada_en} />}</p>
          <ContenidoClinico version={data.consulta.version_vigente} tipo="consulta" />
          {rol !== ROLES.PACIENTE && <Link to={`/citas/${id}/consulta/versiones`}>Ver versiones de consulta</Link>}
        </>}
      </section>
      <section className="clinical-section" aria-labelledby="clinical-background"><h2 id="clinical-background">Antecedentes vigentes</h2>
        <p>Información actual del paciente; no representa necesariamente lo registrado en la fecha de esta cita.</p>
        <ContenidoClinico version={data.antecedentes} tipo="antecedentes" />
        {rol !== ROLES.PACIENTE && <Link to={`/historia-clinica/pacientes/${data.paciente.id_paciente}/antecedentes/versiones`}>Ver versiones de antecedentes</Link>}
      </section>
      <section className="clinical-section" aria-labelledby="clinical-studies"><h2 id="clinical-studies">Estudios asociados</h2>
        {rol !== ROLES.PACIENTE && data.consulta && <p><Link className="button button--primary" to={`/citas/${id}/consulta/solicitar-estudio`}>Solicitar estudio</Link></p>}
        <p>Abre una solicitud para consultar su documento vigente autorizado.</p>
        {data.consulta && <p><Link to={`/laboratorio/consultas/${data.consulta.id_consulta}`}>Ver estudios de esta consulta</Link></p>}
        {data.estudios.length ? <ul className="clinical-visits">{data.estudios.map(e => <li key={e.id_solicitud}><h3>{e.estudio_solicitado}</h3>
          <p>Solicitud: <FechaClinica value={e.solicitada_en} /></p><p>Estado: {e.estado}</p>
           {e.resultado && <p>Publicación del resultado vigente: <FechaClinica value={e.resultado.publicado_en} /></p>}
           <Link to={`/laboratorio/solicitudes/${e.id_solicitud}`}>Ver solicitud de laboratorio</Link>
        </li>)}</ul> : <p>No hay estudios asociados en esta página.</p>}
        <Pagination meta={data.meta} label="estudios" onPage={value => { setPage(value); results.current?.focus(); }} />
      </section>
    </>}</ClinicaQuery></div>
  </section>;
}
