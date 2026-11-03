import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Icon from '../../../components/Icon';
import PageHeading from '../../../components/PageHeading';
import ActionGroup from '../../../components/ActionGroup';
import Pagination from '../../../components/Pagination';
import QueryMessage from '../../../components/QueryMessage';
import PatientsTable from '../components/PatientsTable';
import PatientFailure from '../components/PatientFailure';
import { usePatientQuery } from '../hooks/usePatientQuery';
import '../styles/patients.css';
import '../../../styles/directory.css';

const empty = { documento_identidad: '', nombres: '', apellidos: '', activo: '' };
export default function PatientsPage({ historial = false, gestion = false, historia = false, laboratorio = false, onSeleccionar,
  tituloSeleccion = 'Seleccionar paciente de destino', descripcionSeleccion = 'Busca al paciente de destino. Seleccionarlo todavía no corrige la asociación del documento.' }) {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const { data, failure, loading, reload } = usePatientQuery('list', filters);
  const results = useRef(null);
  const field = name => ({ value: draft[name], onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: failure?.fields[name] });
  const paginate = page => { setFilters({ ...filters, page }); results.current?.focus(); };
  const directorio = !historial && !gestion && !historia && !laboratorio && !onSeleccionar;
  return <section className={`workspace-page patients-page${directorio ? ' directory-page' : ''}`}>
    <PageHeading title={onSeleccionar ? tituloSeleccion : laboratorio ? 'Seleccionar paciente para consultar estudios' : historia ? 'Seleccionar paciente para consultar historia clínica' : gestion ? 'Seleccionar paciente para gestionar una cita' : historial ? 'Seleccionar paciente para consultar historial' : 'Pacientes'}
      description={onSeleccionar ? descripcionSeleccion : laboratorio ? 'Busca al paciente y consulta sus estudios y resultados. Incluye fichas inactivas o sin cuenta web.' : historia ? 'Busca al paciente y abre su historia clínica. También puedes consultar fichas inactivas o sin cuenta web.' : gestion ? 'Busca al paciente, abre su ficha y selecciona Consultar historial de citas. Desde el detalle de una cita puedes consultar su gestión, incluidas fichas inactivas o sin cuenta web.'
        : historial ? 'Busca al paciente, abre su ficha y selecciona Consultar historial de citas. También puedes consultar fichas inactivas o sin cuenta web.' : 'Busca y consulta las fichas administrativas de la clínica.'}
      actions={!historial && !historia && !laboratorio && !onSeleccionar && <Link className="button button--primary" to="/recepcion/pacientes/nuevo"><Icon name="user" />Registrar paciente</Link>} />
    <form className={`patients-filters${directorio ? ' directory-filters directory-filters--patients' : ''}`} aria-label="Buscar pacientes" onSubmit={event => {
      event.preventDefault();
      setFilters({ documento_identidad: draft.documento_identidad.trim(), nombres: draft.nombres.trim(), apellidos: draft.apellidos.trim(), activo: draft.activo, page: 1 });
      reload();
    }}>
      <FormField label="CI" maxLength={50} autoComplete="off" {...field('documento_identidad')} />
      <FormField label="Nombres" maxLength={120} autoComplete="off" {...field('nombres')} />
      <FormField label="Apellidos" maxLength={120} autoComplete="off" {...field('apellidos')} />
      <SelectField label="Estado de ficha" {...field('activo')}><option value="">Todos los estados</option><option value="1">Activa</option><option value="0">Inactiva</option></SelectField>
      <ActionGroup><Button type="submit" variant={directorio ? 'secondary' : 'primary'}>Buscar</Button><Button variant="quiet" onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); reload(); }}>Limpiar filtros</Button></ActionGroup>
    </form>
    <div ref={results} tabIndex="-1" aria-busy={loading} className={directorio ? 'directory-results' : undefined}>
      {loading ? <QueryMessage loading title="Consultando pacientes…" /> : failure ? <div className="query-failure"><PatientFailure failure={failure} /><Button variant="secondary" onClick={reload}>Volver a consultar</Button></div>
        : <>{directorio && <header className="directory-summary"><h2>Resultados</h2><p><strong>{data.meta.total}</strong> {data.meta.total === 1 ? 'ficha encontrada' : 'fichas encontradas'}</p></header>}
          {data.data.length ? <PatientsTable patients={data.data} historia={historia} laboratorio={laboratorio} onSeleccionar={onSeleccionar} directorio={directorio} /> : <QueryMessage title="No hay pacientes para estos filtros" description="Prueba otro CI, nombre, apellido o estado de ficha." />}
          <Pagination meta={data.meta} label="pacientes" onPage={paginate} /></>}
    </div>
    <p className="patients-note">Una ficha puede existir sin CI y sin cuenta web. El estado de la ficha es independiente del acceso a la cuenta.</p>
  </section>;
}
