import { useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import CatalogResults from '../../../components/CatalogResults';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarMedicos } from '../../medicos/services/medico.service';
import '../../../styles/catalogs.css';

const empty = { nombre: '', activo: '' }, fields = ['nombre', 'activo', 'page', 'per_page'];
export default function HorariosPage() {
  const [draft, setDraft] = useState(empty), [filters, setFilters] = useState({ ...empty, page: 1 });
  const query = useCatalogQuery(listarMedicos, filters, fields);
  return <section className="workspace-page catalog-page"><header className="page-heading"><h1>Horarios de atención</h1><p>Selecciona un Médico para administrar sus intervalos semanales, incluidos los de profesionales inactivos.</p><Link className="button button--secondary" to="/administracion/horarios/configuracion">Configurar duración global</Link></header>
    <form className="catalog-filters" aria-label="Buscar médico para horarios" onSubmit={event => { event.preventDefault(); setFilters({ ...draft, page: 1 }); query.reload(); }}>
      <FormField label="Nombre del médico" maxLength={120} value={draft.nombre} onChange={event => setDraft({ ...draft, nombre: event.target.value })} error={query.failure?.fields.nombre} />
      <SelectField label="Actividad profesional" value={draft.activo} onChange={event => setDraft({ ...draft, activo: event.target.value })} error={query.failure?.fields.activo}><option value="">Todos los médicos</option><option value="1">Activos</option><option value="0">Inactivos</option></SelectField>
      <div className="catalog-actions"><Button type="submit" disabled={query.remaining > 0}>Buscar médico</Button><Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); query.reload(); }}>Limpiar filtros</Button></div>
    </form>
    <CatalogResults query={query} label="médicos" loadingLabel="Consultando médicos…" emptyTitle="No hay médicos para estos filtros" emptyHint="Busca otro nombre o incluye todos los estados." onPage={page => setFilters({ ...filters, page })}>
      <div className="catalog-table-wrap"><table className="catalog-table" role="table"><caption>Médicos para configurar horarios</caption><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Médico</th><th scope="col" role="columnheader">Actividad</th></tr></thead>
        <tbody role="rowgroup">{query.data?.data.map(medico => <tr key={medico.id_medico} role="row"><td role="cell"><Link to={`/administracion/horarios/medicos/${medico.id_medico}`}>Horarios de {medico.nombres} {medico.apellidos}</Link><p>{medico.codigo_medico}</p></td>
          <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Actividad</span>{medico.activo ? 'Activo' : 'Inactivo'}</td></tr>)}</tbody></table></div>
    </CatalogResults>
  </section>;
}
