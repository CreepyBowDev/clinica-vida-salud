import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { clinicalPresentation } from '../../../constants/clinicalPresentation';
import ClinicalModeError from '../components/ClinicalModeError';
import SeleccionCitaClinica from '../components/SeleccionCitaClinica';
import PageHeading from '../../../components/PageHeading';
import Pagination from '../../../components/Pagination';
import QueryMessage from '../../../components/QueryMessage';
import Button from '../../../components/Button';
import { escucharCambioSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import { consultarHistoria } from '../services/historiaClinica.service';
import { useClinicaQuery } from '../hooks/useClinicaQuery';
import ClinicaQuery from '../components/ClinicaQuery';
import { ContenidoClinico, FechaClinica, IdentidadClinica } from '../components/ContenidoClinico';
import '../styles/clinica.css';
import '../styles/clinical-reading.css';

export default function HistoriaClinicaPage() {
  const { id } = useParams(), { usuario } = useAuth();
  const location = useLocation(), entry = clinicalPresentation(location.pathname, location.search, usuario.rol);
  if (!entry.valid) return <ClinicalModeError />;
  if (id !== undefined && !esIdentificador(id)) return <section className="workspace-page"><h1>Historia no disponible</h1><Link to="/historia-clinica">Volver a historia clínica</Link></section>;
  return <Historia key={`${usuario.id_usuario}:${usuario.rol}:${id ?? 'entrada'}`} id={id} rol={usuario.rol} />;
}

function Historia({ id, rol }) {
  if (!id && rol === ROLES.ADMINISTRADOR) return <SeleccionAdministrativa />;
  if (!id && rol === ROLES.MEDICO) return <SeleccionCitaClinica recorrido="historia" />;
  return <HistoriaPaciente id={id} rol={rol} />;
}

function SeleccionAdministrativa() {
  const [hidden, setHidden] = useState(false), [revision, setRevision] = useState(0);
  const [paused, setPaused] = useState(false);
  useEffect(() => {
    const hide = () => setHidden(true);
    const show = () => { setHidden(false); setRevision(value => value + 1); };
    window.addEventListener('pagehide', hide); window.addEventListener('pageshow', show);
    return () => { window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show); };
  }, []);
  useEffect(() => escucharCambioSesion(() => setPaused(true)), []);
  if (paused) return <section className="workspace-page"><QueryMessage title="La sesión cambió" description="Vuelve a consultar antes de seleccionar un paciente." />
    <Button variant="secondary" onClick={() => { setPaused(false); setRevision(value => value + 1); }}>Volver a consultar</Button></section>;
  return hidden ? <QueryMessage loading title="Consulta de pacientes pausada" /> : <PatientsPage key={revision} historia />;
}

function HistoriaPaciente({ id, rol }) {
  const [page, setPage] = useState(1), results = useRef(null);
  const query = useClinicaQuery(consultarHistoria, { ...(id ? { id } : { propia: true }), rol, page });
  return <section className="workspace-page clinical-page clinical-reading">
    <Link className="back-link" to={id ? rol === ROLES.MEDICO ? '/agenda' : '/historia-clinica' : '/inicio'}>{id ? rol === ROLES.MEDICO ? 'Volver a agenda médica' : 'Volver a seleccionar paciente' : 'Volver a Inicio'}</Link>
    <PageHeading title={rol === ROLES.PACIENTE ? 'Mi historia clínica' : 'Historia clínica'} description="Antecedentes vigentes y consultas registradas. Horarios en America/La_Paz." />
    <div ref={results} tabIndex={-1} className="clinical-sheet"><ClinicaQuery query={query}>{data => <>
      <IdentidadClinica paciente={data.paciente} />
      <section className="clinical-section" aria-labelledby="clinical-background"><h2 id="clinical-background">Antecedentes vigentes</h2>
        <ContenidoClinico version={data.antecedentes} tipo="antecedentes" />
        {rol !== ROLES.PACIENTE && <Link to={`/historia-clinica/pacientes/${data.paciente.id_paciente}/antecedentes/versiones`}>Ver versiones de antecedentes</Link>}
      </section>
      <section className="clinical-section" aria-labelledby="clinical-visits"><h2 id="clinical-visits">Consultas</h2>
        {data.consultas.length ? <ol className="clinical-visits">{data.consultas.map(c => <li key={c.id_consulta}>
          <p>Fecha programada: <FechaClinica value={c.inicio_at} /></p><h3>{c.medico.nombres} {c.medico.apellidos}</h3><p>{c.tipo_consulta}</p>
          <Link to={`/citas/${c.id_cita}/consulta?recorrido=historia`} aria-label={`Ver consulta ${c.codigo_consulta}`}>Ver consulta</Link>
        </li>)}</ol> : <QueryMessage title="No hay consultas registradas" description="Los antecedentes disponibles se muestran por separado." />}
        <Pagination meta={data.meta} label="consultas" onPage={value => { setPage(value); results.current?.focus(); }} />
      </section>
    </>}</ClinicaQuery></div>
  </section>;
}
