import { useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import CatalogResults from '../../../components/CatalogResults';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarConsultorios } from '../services/consultorio.service';

const fields = ['q', 'page', 'per_page'];
export default function ConsultorioSelector({ value, onChange, disabled, error }) {
  const [q, setQ] = useState('');
  const [filters, setFilters] = useState({ q: '', activo: '1', page: 1, per_page: 20 });
  const query = useCatalogQuery(listarConsultorios, filters, fields);
  const options = query.data?.data || [];
  const outside = value && !options.some(item => item.id_consultorio === value.id_consultorio);
  const search = () => { if (disabled || query.remaining > 0) return; setFilters({ ...filters, q: q.trim(), page: 1 }); query.reload(); };
  return <fieldset className="catalog-specialty-filter" disabled={disabled}><legend>Consultorio de destino</legend>
    <p>Busca entre los consultorios activos de la sede. Puedes recorrer todas las páginas del catálogo.</p>
    <div className="catalog-specialty-search"><FormField label="Buscar código o nombre de consultorio" value={q} maxLength={120} autoComplete="off" error={query.failure?.fields.q}
      onChange={event => setQ(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); search(); } }} />
      <Button variant="secondary" disabled={query.remaining > 0} onClick={search}>Buscar consultorio</Button></div>
    <SelectField label="Seleccionar consultorio de destino" value={value?.id_consultorio || ''} error={error} onChange={event => {
      const selected = options.find(item => item.id_consultorio === event.target.value) || (value?.id_consultorio === event.target.value ? value : null);
      onChange(selected);
    }}>
      <option value="">Selecciona un consultorio</option>
      {outside && <option value={value.id_consultorio}>{value.codigo_consultorio} — {value.nombre}</option>}
      {options.map(item => <option key={item.id_consultorio} value={item.id_consultorio}>{item.codigo_consultorio} — {item.nombre}</option>)}
    </SelectField>
    <div aria-busy={query.loading}><CatalogResults query={query} label="opciones de consultorio" loadingLabel="Consultando consultorios activos…" emptyTitle="No hay consultorios activos para esta búsqueda" emptyHint="Prueba otro código o nombre."
      onPage={page => setFilters({ ...filters, page })} /></div>
  </fieldset>;
}
