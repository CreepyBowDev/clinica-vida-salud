import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import CatalogResults from '../../../components/CatalogResults';
import SpecialtyFilter from '../components/SpecialtyFilter';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarMedicos } from '../services/medico.service';
import '../../../styles/catalogs.css';

const empty = { nombre: '', activo: '', id_especialidad: '' };
const fields = ['nombre', 'activo', 'id_especialidad', 'page', 'per_page'];
export default function DoctorsPage() {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const [catalogRevision, setCatalogRevision] = useState(0);
  const query = useCatalogQuery(listarMedicos, filters, fields);
  const results = useRef(null);
  const field = name => ({ value: draft[name], onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: query.failure?.fields[name] });
  return <section className="workspace-page catalog-page">
    <header className="page-heading"><h1>Médicos</h1><p>Consulta los profesionales y sus especialidades habilitadas.</p><Link className="button button--primary" to="/administracion/medicos/nuevo">Registrar médico</Link></header>
    <form className="catalog-filters" aria-label="Buscar médicos" onSubmit={event => {
      event.preventDefault(); setFilters({ ...draft, nombre: draft.nombre.trim(), page: 1 }); query.reload();
    }}>
      <FormField label="Nombre o apellidos" maxLength={241} autoComplete="off" {...field('nombre')} />
      <SelectField label="Actividad profesional" {...field('activo')}><option value="">Todos los estados</option><option value="1">Activo</option><option value="0">Inactivo</option></SelectField>
      <SpecialtyFilter key={catalogRevision} value={draft.id_especialidad} onChange={id_especialidad => setDraft(current => ({ ...current, id_especialidad }))} error={query.failure?.fields.id_especialidad} />
      <div className="catalog-actions"><Button type="submit" disabled={query.remaining > 0}>Buscar</Button><Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); setCatalogRevision(value => value + 1); query.reload(); }}>Limpiar filtros</Button></div>
    </form>
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      <CatalogResults query={query} label="médicos" loadingLabel="Consultando médicos…" emptyTitle="No hay médicos para estos filtros" emptyHint="Prueba otro nombre, especialidad o estado profesional."
        onPage={page => { setFilters({ ...filters, page }); results.current?.focus(); }}>
        <div className="catalog-table-wrap"><table className="catalog-table" role="table"><caption>Profesionales · más recientes primero</caption>
          <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Médico</th><th scope="col" role="columnheader">Código profesional</th><th scope="col" role="columnheader">Especialidades habilitadas</th><th scope="col" role="columnheader">Actividad profesional</th></tr></thead>
          <tbody role="rowgroup">{query.data?.data.map(item => <tr key={item.id_medico} role="row">
            <td role="cell"><Link to={`/administracion/medicos/${item.id_medico}`} aria-label={`Ver médico ${item.nombres} ${item.apellidos}`}><strong>{item.nombres} {item.apellidos}</strong></Link></td><td role="cell"><span className="catalog-cell-label" aria-hidden="true">Código profesional</span>{item.codigo_medico}</td>
            <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Especialidades habilitadas</span><ul className="catalog-specialties">{item.especialidades.map(specialty => <li key={specialty.id_especialidad}>{specialty.nombre}{!specialty.activa && <span className="text-muted"> (catálogo inactivo)</span>}</li>)}</ul></td>
            <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Actividad profesional</span><span className={`catalog-status${item.activo ? '' : ' catalog-status--inactive'}`}>{item.activo ? 'Activo' : 'Inactivo'}</span></td>
          </tr>)}</tbody></table></div>
      </CatalogResults>
    </div>
    <p className="catalog-note">La actividad profesional es independiente de la cuenta de acceso. Las especialidades habilitadas no garantizan disponibilidad de turnos.</p>
  </section>;
}
