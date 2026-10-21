import { useId, useRef, useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import CatalogResults from '../../../components/CatalogResults';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarEspecialidades } from '../../especialidades/services/especialidad.service';

const fields = ['nombre', 'page', 'per_page'];
export default function InitialSpecialties({ selected, onChange, error, managing = false }) {
  const [name, setName] = useState('');
  const [filters, setFilters] = useState({ nombre: '', page: 1 });
  const query = useCatalogQuery(listarEspecialidades, filters, fields);
  const results = useRef(null);
  const help = useId();
  function search() { if (query.remaining) return; setFilters({ nombre: name.trim(), page: 1 }); query.reload(); }
  return <fieldset className="catalog-specialty-filter doctor-initial-specialties" aria-describedby={help} aria-invalid={Boolean(error)} tabIndex={error ? -1 : undefined}>
    <legend>{managing ? 'Especialidades habilitadas' : 'Especialidades iniciales'}</legend>
    <p id={help}>{error || 'Selecciona al menos una. Asociar una especialidad inactiva no la activa ni genera turnos disponibles.'}</p>
    <div className="catalog-specialty-search"><FormField label="Buscar especialidad por nombre" value={name} maxLength={120} error={query.failure?.fields.nombre} onChange={event => setName(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); search(); } }} />
      <Button variant="secondary" onClick={search} disabled={query.remaining > 0}>Buscar especialidades</Button></div>
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      <CatalogResults query={query} label="especialidades del catálogo" loadingLabel="Consultando especialidades…" emptyTitle="No hay especialidades para este nombre" emptyHint="Prueba otra búsqueda o registra una especialidad en el catálogo."
        onPage={page => { setFilters({ ...filters, page }); results.current?.focus(); }}>
        <div className="doctor-specialty-options">{query.data?.data.map(item => <label key={item.id_especialidad}><input type="checkbox" checked={selected.some(value => value.id_especialidad === item.id_especialidad)} onChange={event => onChange(event.target.checked ? [...selected, item] : selected.filter(value => value.id_especialidad !== item.id_especialidad))} />{item.nombre}{!item.activa && ' (catálogo inactivo)'}</label>)}</div>
      </CatalogResults>
    </div>
    <section className="doctor-selected" aria-label="Especialidades seleccionadas"><h3>Selección actual ({selected.length})</h3>
      {selected.length ? <ul>{selected.map(item => <li key={item.id_especialidad}><span>{item.nombre}{!item.activa && ' (catálogo inactivo)'}</span><Button variant="quiet" onClick={() => onChange(selected.filter(value => value.id_especialidad !== item.id_especialidad))} aria-label={`Quitar ${item.nombre}`}>Quitar</Button></li>)}</ul> : <p>No has seleccionado especialidades.</p>}
    </section>
  </fieldset>;
}
