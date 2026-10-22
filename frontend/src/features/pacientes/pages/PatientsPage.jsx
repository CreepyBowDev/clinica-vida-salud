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

const empty = { documento_identidad: '', nombres: '', apellidos: '', activo: '' };
export default function PatientsPage() {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const { data, failure, loading, reload } = usePatientQuery('list', filters);
  const results = useRef(null);
  const field = name => ({ value: draft[name], onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: failure?.fields[name] });
  const paginate = page => { setFilters({ ...filters, page }); results.current?.focus(); };
  return <section className="workspace-page patients-page">
    <PageHeading title="Pacientes" description="Busca y consulta las fichas administrativas de la clínica." actions={<Link className="button button--primary" to="/recepcion/pacientes/nuevo"><Icon name="user" />Registrar paciente</Link>} />
    <form className="patients-filters" aria-label="Buscar pacientes" onSubmit={event => {
      event.preventDefault();
      setFilters({ documento_identidad: draft.documento_identidad.trim(), nombres: draft.nombres.trim(), apellidos: draft.apellidos.trim(), activo: draft.activo, page: 1 });
      reload();
    }}>
      <FormField label="CI" maxLength={50} autoComplete="off" {...field('documento_identidad')} />
      <FormField label="Nombres" maxLength={120} autoComplete="off" {...field('nombres')} />
      <FormField label="Apellidos" maxLength={120} autoComplete="off" {...field('apellidos')} />
      <SelectField label="Estado de ficha" {...field('activo')}><option value="">Todos los estados</option><option value="1">Activa</option><option value="0">Inactiva</option></SelectField>
      <ActionGroup><Button type="submit">Buscar</Button><Button variant="quiet" onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); reload(); }}>Limpiar filtros</Button></ActionGroup>
    </form>
    <div ref={results} tabIndex="-1" aria-busy={loading}>
      {loading ? <QueryMessage loading title="Consultando pacientes…" /> : failure ? <div className="query-failure"><PatientFailure failure={failure} /><Button variant="secondary" onClick={reload}>Volver a consultar</Button></div>
        : <>{data.data.length ? <PatientsTable patients={data.data} /> : <QueryMessage title="No hay pacientes para estos filtros" description="Prueba otro CI, nombre, apellido o estado de ficha." />}
          <Pagination meta={data.meta} label="pacientes" onPage={paginate} /></>}
    </div>
    <p className="patients-note">Una ficha puede existir sin CI y sin cuenta web. El estado de la ficha es independiente del acceso a la cuenta.</p>
  </section>;
}
