import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import { escucharCambioSesion } from '../../../api/api';
import PageHeading from '../../../components/PageHeading';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Button from '../../../components/Button';
import Pagination from '../../../components/Pagination';
import QueryMessage from '../../../components/QueryMessage';
import ActionGroup from '../../../components/ActionGroup';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esFechaCivil } from '../../agenda/utils/agendaFormat';
import { listarSolicitudes } from '../services/laboratorio.service';
import { useLaboratorioQuery } from '../hooks/useLaboratorioQuery';
import LaboratorioQuery from '../components/LaboratorioQuery';
import SolicitudesTable from '../components/SolicitudesTable';
import '../styles/laboratorio.css';
import '../styles/lab-workflow.css';

const empty = { estado: '', fecha_desde: '', fecha_hasta: '' };
export default function LaboratorioPage({ contexto }) {
  const { id } = useParams(), { usuario } = useAuth();
  if (contexto && !esIdentificador(id)) return <section className="workspace-page"><h1>Laboratorio no disponible</h1><Link to="/laboratorio">Volver a estudios</Link></section>;
  if (usuario.rol === ROLES.ADMINISTRADOR && !contexto) return <SeleccionPaciente key={`${usuario.id_usuario}:${usuario.rol}`} />;
  return <Listado key={`${usuario.id_usuario}:${usuario.rol}:${contexto}:${id}`} rol={usuario.rol} contexto={contexto} id={id} />;
}
function SeleccionPaciente() {
  const [hidden, setHidden] = useState(false), [paused, setPaused] = useState(false), [revision, setRevision] = useState(0);
  useEffect(() => {
    const hide = () => setHidden(true), show = () => { setHidden(false); setRevision(v => v + 1); };
    const unsubscribe = escucharCambioSesion(() => setPaused(true));
    window.addEventListener('pagehide', hide); window.addEventListener('pageshow', show);
    return () => { unsubscribe(); window.removeEventListener('pagehide', hide); window.removeEventListener('pageshow', show); };
  }, []);
  if (hidden) return <QueryMessage loading title="Consulta de pacientes pausada" />;
  if (paused) return <section className="workspace-page"><QueryMessage title="La sesión cambió" description="Vuelve a consultar antes de seleccionar un paciente." />
    <Button variant="secondary" onClick={() => { setPaused(false); setRevision(v => v + 1); }}>Volver a consultar</Button></section>;
  return <PatientsPage key={revision} laboratorio />;
}
function Listado({ rol, contexto, id }) {
  const [draft, setDraft] = useState(empty), [filters, setFilters] = useState({ ...empty, page: 1 }), [error, setError] = useState('');
  const results = useRef(null);
  const query = useLaboratorioQuery(listarSolicitudes, { rol, ...filters, ...(contexto === 'paciente' ? { paciente: id } : contexto === 'consulta' ? { consulta: id } : {}) });
  const field = name => ({ value: draft[name], onChange: e => setDraft(v => ({ ...v, [name]: e.target.value })) });
  function submit(e) {
    e.preventDefault();
    if (['fecha_desde', 'fecha_hasta'].some(k => draft[k] && !esFechaCivil(draft[k])) || (draft.fecha_desde && draft.fecha_hasta && draft.fecha_desde > draft.fecha_hasta)) {
      setError('La fecha final debe ser igual o posterior a la inicial y ambas deben ser válidas.'); return;
    }
    setError(''); setFilters({ ...draft, page: 1 }); query.reload(); results.current?.focus();
  }
  return <section className="workspace-page lab-page lab-flow lab-worklist">
    {contexto && <Link className="back-link" to="/laboratorio">{rol === ROLES.ADMINISTRADOR ? 'Seleccionar otro paciente' : 'Ver todos mis estudios'}</Link>}
    <PageHeading title="Estudios y resultados" description={contexto === 'consulta' ? 'Estudios de la consulta seleccionada. Horarios en America/La_Paz.' : contexto === 'paciente' ? 'Estudios del paciente seleccionado. Horarios en America/La_Paz.' : rol === ROLES.PACIENTE ? 'Consulta tus estudios y descarga los resultados disponibles. Horarios en America/La_Paz.' : 'Solicitudes de tus consultas y documentos vigentes. Horarios en America/La_Paz.'} />
    {contexto === 'consulta' && rol !== ROLES.PACIENTE && <p><Link to="/laboratorio/solicitar">Para solicitar otro estudio, abre la consulta desde la agenda o historia clínica</Link></p>}
    <form className="lab-filters" aria-label="Filtrar estudios" onSubmit={submit}>
      <SelectField label="Estado" {...field('estado')}><option value="">Todos los estados</option><option>Pendiente</option>{rol !== ROLES.PACIENTE && <option>En revisión</option>}<option>Disponible</option></SelectField>
      <FormField label="Desde" type="date" {...field('fecha_desde')} /><FormField label="Hasta" type="date" {...field('fecha_hasta')} />
      <ActionGroup><Button type="submit" disabled={query.remaining > 0}>Consultar</Button><Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft(empty); setError(''); setFilters({ ...empty, page: 1 }); query.reload(); }}>Limpiar filtros</Button></ActionGroup>
      {error && <p role="alert">{error}</p>}
    </form>
    <div className="lab-worklist-results" ref={results} tabIndex={-1} role="region" aria-label="Resultados de estudios"><h2>Estudios solicitados</h2><LaboratorioQuery query={query}>{data => <>
      <p className="lab-worklist-total">Total del servidor: {data.meta.total} {data.meta.total === 1 ? 'estudio' : 'estudios'}</p>
      {contexto === 'paciente' && data.solicitudes[0] && <p className="lab-patient-context"><strong>{data.solicitudes[0].paciente.nombres} {data.solicitudes[0].paciente.apellidos}</strong> — {data.solicitudes[0].paciente.codigo_paciente}</p>}
      {data.solicitudes.length ? <SolicitudesTable solicitudes={data.solicitudes} propia={rol === ROLES.PACIENTE} /> : <QueryMessage title="No hay estudios para estos filtros" description="Prueba otros filtros o vuelve a consultar más tarde." />}
      <Pagination meta={data.meta} label="estudios" onPage={page => { setFilters(v => ({ ...v, page })); results.current?.focus(); }} />
    </>}</LaboratorioQuery></div>
  </section>;
}
