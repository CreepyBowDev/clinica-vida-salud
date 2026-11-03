import { useId, useRef, useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import CatalogResults from '../../../components/CatalogResults';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';

// Frontera de selección: solo catálogos autorizados, sin enlaces/criterios en history.state.
export default function ReservaCatalogo({ titulo, etiqueta, load, base = {}, campos, seleccionado, onSelect, onInvalidate, describir, identificador, accionCambio, prefijoSeleccion, tituloSeleccionado }) {
  const [draft, setDraft] = useState(() => Object.fromEntries(campos.map(c => [c.name, ''])));
  const [filters, setFilters] = useState({ page: 1, per_page: 10 });
  const query = useCatalogQuery(load, { ...base, ...filters }, [...campos.map(c => c.name), 'page', 'per_page']);
  const [expanded, setExpanded] = useState(false);
  const bodyId = useId();
  const heading = useRef(null);
  const compact = Boolean(seleccionado) && !expanded;
  function editar(name, value) { setDraft({ ...draft, [name]: value }); onInvalidate(); }
  return <section className="reserva-section reserva-catalog" data-selected={Boolean(seleccionado)} aria-label={titulo} aria-busy={query.loading}>
    <header className="reserva-catalog-heading"><h2 ref={heading} tabIndex="-1">{seleccionado ? tituloSeleccionado ?? titulo : titulo}</h2>
      {seleccionado && <Button variant="quiet" aria-expanded={!compact} aria-controls={bodyId} onClick={() => setExpanded(!expanded)}>{compact ? accionCambio : 'Cerrar búsqueda'}</Button>}</header>
    {seleccionado && <p className="reserva-catalog-selection">{prefijoSeleccion ? `${prefijoSeleccion}: ` : ''}{describir(seleccionado)}</p>}
    <div id={bodyId} hidden={compact}>
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
          onClick={() => { onSelect(item); setExpanded(false); heading.current?.focus({ preventScroll: true }); }}>{describir(item)}</Button>
      </li>)}</ul>
    </CatalogResults>
    </div>
  </section>;
}
