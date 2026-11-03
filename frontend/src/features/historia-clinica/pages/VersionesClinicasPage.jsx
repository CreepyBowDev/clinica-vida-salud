import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import PageHeading from '../../../components/PageHeading';
import Pagination from '../../../components/Pagination';
import { useAuth } from '../../../hooks/useAuth';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarVersiones } from '../services/historiaClinica.service';
import { useClinicaQuery } from '../hooks/useClinicaQuery';
import ClinicaQuery from '../components/ClinicaQuery';
import { ContenidoClinico, FechaClinica } from '../components/ContenidoClinico';
import '../styles/clinica.css';
import '../../../styles/version-history.css';

export default function VersionesClinicasPage({ tipo }) {
  const { id } = useParams(), { usuario } = useAuth();
  if (!esIdentificador(id)) return <section className="workspace-page"><h1>Versiones no disponibles</h1><Link to="/historia-clinica">Volver a historia clínica</Link></section>;
  return <Versiones key={`${usuario.id_usuario}:${usuario.rol}:${tipo}:${id}`} id={id} rol={usuario.rol} tipo={tipo} />;
}
function Versiones({ id, rol, tipo }) {
  const [page, setPage] = useState(1), results = useRef(null);
  const query = useClinicaQuery(consultarVersiones, { id, rol, tipo, page });
  const consulta = tipo === 'consulta';
  return <section className="workspace-page clinical-page version-history">
    <Link className="back-link" to={consulta ? `/citas/${id}/consulta` : `/historia-clinica/pacientes/${id}`}>{consulta ? 'Volver a consulta' : 'Volver a historia clínica'}</Link>
    <PageHeading title={`Versiones de ${consulta ? 'consulta' : 'antecedentes'}`} description="Histórico clínico protegido de solo lectura. Fechas en America/La_Paz." />
    <div className="version-results version-sheet" ref={results} tabIndex={-1} role="region" aria-label="Versiones clínicas"><ClinicaQuery query={query}>{data => <>
      {data.versiones.length ? <ol className="clinical-versions version-list">{data.versiones.map(v => <li key={consulta ? v.id_version_consulta : v.id_version_antecedente}>
        <article className="clinical-section"><header className="version-heading"><h2>Versión {v.numero_version}</h2><p>Registrada: <FechaClinica value={v.registrada_en} /></p></header>
          <div className="version-meta">
          <p>Identificador de usuario ejecutor: {v.id_autor}</p><p>Motivo de corrección: {v.motivo_cambio ?? 'No registrado'}</p>
          {!consulta && <p>Identificador de consulta de origen: {v.id_consulta_origen}</p>}</div>
          <ContenidoClinico version={v} tipo={tipo} />
        </article>
      </li>)}</ol> : <p>No hay versiones clínicas registradas.</p>}
      <Pagination meta={data.meta} label="versiones" onPage={value => { setPage(value); results.current?.focus(); }} />
    </>}</ClinicaQuery></div>
  </section>;
}
