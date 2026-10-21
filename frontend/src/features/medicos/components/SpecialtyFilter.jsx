import { useRef, useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import CatalogResults from '../../../components/CatalogResults';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarEspecialidades } from '../../especialidades/services/especialidad.service';

const fields = ['nombre', 'page', 'per_page'];
export default function SpecialtyFilter({ value, onChange, error }) {
  const [name, setName] = useState('');
  const [filters, setFilters] = useState({ nombre: '', page: 1 });
  const [selected, setSelected] = useState(null);
  const query = useCatalogQuery(listarEspecialidades, filters, fields);
  const results = useRef(null);
  const options = query.data?.data || [];
  const selectedOutsidePage = selected?.id_especialidad === value && !options.some(item => item.id_especialidad === value);
  const label = item => `${item.nombre}${item.activa ? '' : ' (inactiva)'}`;
  const search = () => { if (query.remaining > 0) return; setFilters({ nombre: name.trim(), page: 1 }); query.reload(); };
  return <fieldset className="catalog-specialty-filter"><legend>Filtrar por especialidad</legend>
    <p>Busca en todo el catálogo, incluidas las especialidades inactivas.</p>
    <div className="catalog-specialty-search"><FormField label="Buscar nombre de especialidad" value={name} maxLength={120} autoComplete="off" error={query.failure?.fields.nombre} onChange={event => setName(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); search(); } }} />
      <Button variant="secondary" disabled={query.remaining > 0} onClick={search}>Buscar en catálogo</Button></div>
    <SelectField label="Especialidad del médico" value={value} error={error} onChange={event => {
      const id = event.target.value;
      setSelected(options.find(item => item.id_especialidad === id) || (selected?.id_especialidad === id ? selected : null)); onChange(id);
    }}>
      <option value="">Todas las especialidades</option>
      {selectedOutsidePage && <option value={selected.id_especialidad}>{label(selected)}</option>}
      {options.map(item => <option key={item.id_especialidad} value={item.id_especialidad}>{label(item)}</option>)}
    </SelectField>
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      <CatalogResults query={query} label="opciones de especialidad" loadingLabel="Consultando opciones de especialidad…" emptyTitle="No hay opciones para este nombre" emptyHint="Busca otro nombre para encontrar la especialidad."
        onPage={page => { setFilters({ ...filters, page }); results.current?.focus(); }} />
    </div>
  </fieldset>;
}
