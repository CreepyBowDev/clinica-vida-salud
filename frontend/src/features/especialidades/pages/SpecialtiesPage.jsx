import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import CatalogResults from '../../../components/CatalogResults';
import PageHeading from '../../../components/PageHeading';
import ActionGroup from '../../../components/ActionGroup';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { listarEspecialidades } from '../services/especialidad.service';
import '../../../styles/catalogs.css';

const empty = { nombre: '', activa: '' };
const fields = ['nombre', 'activa', 'page', 'per_page'];
export default function SpecialtiesPage() {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const query = useCatalogQuery(listarEspecialidades, filters, fields);
  const results = useRef(null);
  const field = name => ({ value: draft[name], onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: query.failure?.fields[name] });
  return <section className="workspace-page catalog-page">
    <PageHeading title="Especialidades" description="Consulta el catálogo de especialidades médicas de la clínica." actions={<Link className="button button--primary" to="/administracion/especialidades/nueva">Registrar especialidad</Link>} />
    <form className="catalog-filters" aria-label="Buscar especialidades" onSubmit={event => {
      event.preventDefault(); setFilters({ nombre: draft.nombre.trim(), activa: draft.activa, page: 1 }); query.reload();
    }}>
      <FormField label="Nombre de especialidad" maxLength={120} autoComplete="off" {...field('nombre')} />
      <SelectField label="Estado de especialidad" {...field('activa')}><option value="">Todos los estados</option><option value="1">Activa</option><option value="0">Inactiva</option></SelectField>
      <ActionGroup><Button type="submit" disabled={query.remaining > 0}>Buscar</Button><Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); query.reload(); }}>Limpiar filtros</Button></ActionGroup>
    </form>
    <div ref={results} tabIndex="-1" aria-busy={query.loading}>
      <CatalogResults query={query} label="especialidades" loadingLabel="Consultando especialidades…" emptyTitle="No hay especialidades para estos filtros" emptyHint="Prueba otro nombre o selecciona todos los estados."
        onPage={page => { setFilters({ ...filters, page }); results.current?.focus(); }}>
        <div className="catalog-table-wrap query-table"><table className="catalog-table" role="table"><caption>Catálogo de especialidades · más recientes primero</caption>
          <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Especialidad</th><th scope="col" role="columnheader">Descripción</th><th scope="col" role="columnheader">Estado</th></tr></thead>
          <tbody role="rowgroup">{query.data?.data.map(item => <tr key={item.id_especialidad} role="row">
            <td role="cell"><Link to={`/administracion/especialidades/${item.id_especialidad}`} aria-label={`Ver especialidad ${item.nombre}`}><strong>{item.nombre}</strong></Link></td><td role="cell"><span className="catalog-cell-label" aria-hidden="true">Descripción</span>{item.descripcion || 'Sin descripción'}</td>
            <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Estado</span><span className={`catalog-status${item.activa ? '' : ' catalog-status--inactive'}`}>{item.activa ? 'Activa' : 'Inactiva'}</span></td>
          </tr>)}</tbody></table></div>
      </CatalogResults>
    </div>
    <p className="catalog-note">Las especialidades inactivas conservan sus asociaciones e historial. No se ofrecen para nuevas citas.</p>
  </section>;
}
