import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import CatalogResults from '../../../components/CatalogResults';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarConsultorios } from '../services/consultorio.service';
import '../../../styles/catalogs.css';

const empty = { q: '', activo: '', per_page: '20' };
const fields = ['q', 'activo', 'page', 'per_page'];
export default function ConsultoriosPage() {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const query = useCatalogQuery(listarConsultorios, filters, fields);
  const results = useRef(null);
  const field = name => ({ value: draft[name], onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: query.failure?.fields[name] });
  return <section className="workspace-page catalog-page">
    <header className="page-heading"><h1>Consultorios</h1><p>Administra los espacios de atención presencial de la sede.</p><Link className="button button--primary" to="/administracion/consultorios/nuevo">Registrar consultorio</Link></header>
    <form className="catalog-filters" aria-label="Buscar consultorios" onSubmit={event => { event.preventDefault(); setFilters({ ...draft, q: draft.q.trim(), page: 1 }); query.reload(); }}>
      <FormField label="Código o nombre" maxLength={120} autoComplete="off" {...field('q')} />
      <SelectField label="Estado de consultorio" {...field('activo')}><option value="">Todos los estados</option><option value="1">Activo</option><option value="0">Inactivo</option></SelectField>
      <SelectField label="Consultorios por página" {...field('per_page')}>{[10, 20, 50, 100].map(size => <option key={size} value={size}>{size}</option>)}</SelectField>
      <div className="catalog-actions"><Button type="submit" disabled={query.remaining > 0}>Buscar</Button><Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); query.reload(); }}>Limpiar filtros</Button></div>
    </form>
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      <CatalogResults query={query} label="consultorios" loadingLabel="Consultando consultorios…" emptyTitle="No hay consultorios para estos filtros" emptyHint="Prueba otro código o nombre, o selecciona todos los estados."
        onPage={page => { setFilters({ ...filters, page }); results.current?.focus(); }}>
        <div className="catalog-table-wrap"><table className="catalog-table" role="table"><caption>Consultorios de la sede, más recientes primero</caption>
          <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Código</th><th scope="col" role="columnheader">Nombre</th><th scope="col" role="columnheader">Estado</th></tr></thead>
          <tbody role="rowgroup">{query.data?.data.map(item => <tr key={item.id_consultorio} role="row">
            <td role="cell"><Link to={`/administracion/consultorios/${item.id_consultorio}`} aria-label={`Ver consultorio ${item.codigo_consultorio}`}><strong>{item.codigo_consultorio}</strong></Link></td>
            <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Nombre</span>{item.nombre}</td>
            <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Estado</span><span className={`catalog-status${item.activo ? '' : ' catalog-status--inactive'}`}>{item.activo ? 'Activo' : 'Inactivo'}</span></td>
          </tr>)}</tbody></table></div>
      </CatalogResults>
    </div>
    <p className="catalog-note">Desactivar requiere resolver previamente las asignaciones y citas vigentes. No elimina registros ni traslada citas automáticamente.</p>
  </section>;
}
