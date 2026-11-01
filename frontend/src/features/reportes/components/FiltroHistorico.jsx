import { useRef, useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import CatalogResults from '../../../components/CatalogResults';
import { useAgendaQuery } from '../../agenda/hooks/useAgendaQuery';
import { listarMedicosAgenda } from '../../agenda/services/agenda.service';
import { listarEspecialidades } from '../../especialidades/services/especialidad.service';

export default function FiltroHistorico({ tipo, selected, onSelect, required = false }) {
  const medico = tipo === 'medico', label = medico ? 'médico' : 'especialidad';
  const [nombre, setNombre] = useState(''), [filters, setFilters] = useState({ page: 1, per_page: 5 });
  const results = useRef(null);
  const query = useAgendaQuery(medico ? listarMedicosAgenda : listarEspecialidades, filters, { kind: 'selector', allowedFields: ['nombre'] });
  return <details className="catalog-panel agenda-selector">
    <summary>{medico ? 'Médico' : 'Especialidad'}: {selected ? (medico ? `${selected.nombres} ${selected.apellidos}` : selected.nombre) : 'Todos'}</summary>
    <p className="catalog-note">Incluye {medico ? 'médicos inactivos' : 'especialidades inactivas'}. {required ? 'Selección obligatoria para este reporte.' : 'Filtro opcional.'}</p>
    <form className="agenda-search" aria-label={`Buscar ${label} del reporte`} onSubmit={event => {
      event.preventDefault(); setFilters({ nombre: nombre.trim(), page: 1, per_page: 5 }); query.reload();
    }}>
      <FormField label={`Buscar ${label}`} value={nombre} maxLength={120} error={query.failure?.fields?.nombre} onChange={event => setNombre(event.target.value)} />
      <Button type="submit" disabled={query.remaining > 0}>Buscar {label}</Button>
      <Button variant="quiet" onClick={() => onSelect(null)}>Quitar filtro de {label}</Button>
    </form>
    <div ref={results} tabIndex={-1} aria-busy={query.loading}>
    <CatalogResults query={query} label={medico ? 'médicos' : 'especialidades'} loadingLabel={`Consultando ${label}…`}
      emptyTitle={`No hay ${label} para esta búsqueda`} emptyHint="Prueba otro nombre." onPage={page => { setFilters(v => ({ ...v, page })); results.current?.focus(); }}>
      <ul className="agenda-doctors">{query.data?.data.map(item => {
        const id = item[medico ? 'id_medico' : 'id_especialidad'], name = medico ? `${item.nombres} ${item.apellidos}` : item.nombre;
        return <li key={id}><div><strong>{name}</strong><span>{(medico ? item.activo : item.activa) ? 'Activo' : 'Inactivo'}</span></div>
          <Button variant="secondary" aria-pressed={selected?.[medico ? 'id_medico' : 'id_especialidad'] === id}
            onClick={() => onSelect(item)}>Seleccionar {name}</Button></li>;
      })}</ul>
    </CatalogResults>
    </div>
  </details>;
}
