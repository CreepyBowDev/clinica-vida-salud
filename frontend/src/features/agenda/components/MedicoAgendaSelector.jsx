import { useEffect, useRef, useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import CatalogResults from '../../../components/CatalogResults';
import { useAgendaQuery } from '../hooks/useAgendaQuery';
import { listarMedicosAgenda } from '../services/agenda.service';

export default function MedicoAgendaSelector({ selectedId, catalog, onChange, onSelect }) {
  const [expanded, setExpanded] = useState(!selectedId);
  const results = useRef(null);
  const form = useRef(null);
  const query = useAgendaQuery(listarMedicosAgenda, catalog.filters, { kind: 'selector', allowedFields: ['nombre', 'page', 'per_page'] });
  useEffect(() => { if (query.failure?.status === 422) form.current?.querySelector('[aria-invalid="true"]')?.focus(); }, [query.failure]);
  return <details className="catalog-panel agenda-selector" open={expanded} onToggle={event => setExpanded(event.currentTarget.open)}>
    <summary>{selectedId ? 'Cambiar médico de la agenda' : 'Seleccionar médico'}</summary>
    <p className="catalog-note">Incluye profesionales inactivos para consultar sus citas conservadas.</p>
    <form ref={form} className="agenda-search" aria-label="Buscar médico de agenda" onSubmit={event => {
      event.preventDefault(); onChange({ ...catalog, filters: { nombre: catalog.nombre.trim(), page: 1, per_page: 5 } }); query.reload();
    }}>
      <FormField label="Nombre, apellidos o código de médico" maxLength={120} autoComplete="off" value={catalog.nombre}
        error={query.failure?.fields.nombre} onChange={event => onChange({ ...catalog, nombre: event.target.value })} />
      <Button type="submit" disabled={query.remaining > 0}>Buscar médico</Button>
    </form>
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      <CatalogResults query={query} label="médicos" loadingLabel="Consultando médicos de agenda…" emptyTitle="No hay médicos para esta búsqueda"
        emptyHint="Prueba otro nombre, apellido o código." onPage={page => { onChange({ ...catalog, filters: { ...catalog.filters, page } }); results.current?.focus(); }}>
        <ul className="agenda-doctors">{query.data?.data.map(medico => <li key={medico.id_medico}>
          <div><strong>{medico.nombres} {medico.apellidos}</strong><span>{medico.codigo_medico} · {medico.activo ? 'Activo' : 'Inactivo'}</span></div>
          <Button variant="secondary" aria-pressed={selectedId === medico.id_medico} aria-label={`Consultar agenda de ${medico.nombres} ${medico.apellidos}`}
            onClick={() => { onSelect(medico.id_medico); setExpanded(false); }}>Consultar agenda</Button>
        </li>)}</ul>
      </CatalogResults>
    </div>
  </details>;
}
