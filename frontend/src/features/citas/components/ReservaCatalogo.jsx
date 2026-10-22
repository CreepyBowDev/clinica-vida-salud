import { useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import CatalogResults from '../../../components/CatalogResults';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';

// Frontera de selección: solo catálogos autorizados, sin enlaces/criterios en history.state.
export default function ReservaCatalogo({ titulo, etiqueta, load, base = {}, campos, seleccionado, onSelect, onInvalidate, describir, identificador }) {
  const [draft, setDraft] = useState(() => Object.fromEntries(campos.map(c => [c.name, ''])));
  const [filters, setFilters] = useState({ page: 1, per_page: 10 });
  const query = useCatalogQuery(load, { ...base, ...filters }, [...campos.map(c => c.name), 'page', 'per_page']);
  function editar(name, value) { setDraft({ ...draft, [name]: value }); onInvalidate(); }
  return <section className="reserva-section" aria-label={titulo} aria-busy={query.loading}>
    <h2>{titulo}</h2>
    <form className="reserva-filters" aria-label={`Buscar ${etiqueta}`} onSubmit={event => {
      event.preventDefault(); onInvalidate(); setFilters({ ...draft, page: 1, per_page: 10 }); query.reload();
    }}>
      {campos.map(c => <FormField key={c.name} label={c.label} maxLength={c.max ?? 120} autoComplete="off"
        value={draft[c.name]} onChange={e => editar(c.name, e.target.value)} error={query.failure?.fields[c.name]} />)}
      <Button type="submit" disabled={query.remaining > 0}>Buscar {etiqueta}</Button>
    </form>
    <CatalogResults query={{ ...query, reload: () => { onInvalidate(); query.reload(); } }} label={etiqueta} loadingLabel={`Consultando ${etiqueta}…`}
      emptyTitle={`No hay ${etiqueta} para estos criterios`} emptyHint="Prueba otros criterios de búsqueda."
      onPage={page => { onInvalidate(); setFilters({ ...filters, page }); }}>
      <ul className="reserva-options">{query.data?.data.map(item => <li key={item[identificador]}>
        <Button variant="secondary" aria-pressed={seleccionado?.[identificador] === item[identificador]}
          onClick={() => onSelect(item)}>{describir(item)}</Button>
      </li>)}</ul>
    </CatalogResults>
  </section>;
}
