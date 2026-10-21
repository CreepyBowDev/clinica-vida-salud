import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Icon from '../../../components/Icon';
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
    <header className="page-heading patients-heading"><div><h1>Pacientes</h1><p>Busca y consulta las fichas administrativas de la clínica.</p></div><Link className="button button--primary" to="/recepcion/pacientes/nuevo"><Icon name="user" />Registrar paciente</Link></header>
    <form className="patients-filters" aria-label="Buscar pacientes" onSubmit={event => {
      event.preventDefault();
      setFilters({ documento_identidad: draft.documento_identidad.trim(), nombres: draft.nombres.trim(), apellidos: draft.apellidos.trim(), activo: draft.activo, page: 1 });
      reload();
    }}>
      <FormField label="CI" maxLength={50} autoComplete="off" {...field('documento_identidad')} />
      <FormField label="Nombres" maxLength={120} autoComplete="off" {...field('nombres')} />
      <FormField label="Apellidos" maxLength={120} autoComplete="off" {...field('apellidos')} />
      <SelectField label="Estado de ficha" {...field('activo')}><option value="">Todos los estados</option><option value="1">Activa</option><option value="0">Inactiva</option></SelectField>
      <div className="patients-actions"><Button type="submit">Buscar</Button><Button variant="quiet" onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); reload(); }}>Limpiar filtros</Button></div>
    </form>
    <div ref={results} tabIndex="-1" aria-busy={loading}>
      {loading ? <p role="status" className="patients-empty">Consultando pacientes…</p> : failure ? <><PatientFailure failure={failure} /><Button variant="secondary" onClick={reload}>Volver a consultar</Button></>
        : <>{data.data.length ? <PatientsTable patients={data.data} /> : <div className="patients-empty"><h2>No hay pacientes para estos filtros</h2><p>Prueba otro CI, nombre, apellido o estado de ficha.</p></div>}
          <nav className="patients-pagination" aria-label="Paginación de pacientes"><p>{data.meta.total} pacientes · página {data.meta.current_page} de {data.meta.last_page}</p><div>
            <Button variant="secondary" disabled={data.meta.current_page <= 1} onClick={() => paginate(data.meta.current_page - 1)}>Anterior</Button>
            <Button variant="secondary" disabled={data.meta.current_page >= data.meta.last_page} onClick={() => paginate(data.meta.current_page + 1)}>Siguiente</Button>
          </div></nav></>}
    </div>
    <p className="patients-note">Una ficha puede existir sin CI y sin cuenta web. El estado de la ficha es independiente del acceso a la cuenta.</p>
  </section>;
}
