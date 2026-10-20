import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ROLES } from '../../../constants/roles';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import AccountsTable from '../components/AccountsTable';
import AccountFailure from '../components/AccountFailure';
import { useAccountQuery } from '../hooks/useAccountQuery';
import '../styles/accounts.css';

const empty = { rol: '', estado_cuenta: '', correo_acceso: '' };
export default function AccountsPage() {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const { data, failure, loading, reload } = useAccountQuery('list', filters);
  const results = useRef(null);
  const field = name => ({ value: draft[name], onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: failure?.fields[name] });
  const paginate = page => { setFilters({ ...filters, page }); results.current?.focus(); };

  return <section className="workspace-page accounts-page">
    <header className="page-heading accounts-heading"><div><h1>Cuentas de usuario</h1><p>Consulta el acceso del personal y las cuentas verificadas de pacientes.</p></div>
      <Link className="button button--primary" to="/administracion/cuentas/nueva">Crear cuenta del personal</Link></header>
    <form className="accounts-filters" aria-label="Filtrar cuentas" onSubmit={event => {
      event.preventDefault(); setFilters({ ...draft, correo_acceso: draft.correo_acceso.trim(), page: 1 });
    }}>
      <SelectField label="Rol" {...field('rol')}><option value="">Todos los roles</option>{Object.values(ROLES).map(rol => <option key={rol}>{rol}</option>)}</SelectField>
      <SelectField label="Estado de cuenta" {...field('estado_cuenta')}><option value="">Todos los estados</option>{['Pendiente', 'Activa', 'Desactivada'].map(state => <option key={state}>{state}</option>)}</SelectField>
      <FormField label="Correo de acceso exacto" type="email" maxLength={254} autoComplete="off" {...field('correo_acceso')} />
      <div className="accounts-filter-actions"><Button type="submit">Aplicar filtros</Button><Button variant="quiet" onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); reload(); }}>Limpiar</Button></div>
    </form>
    <div ref={results} tabIndex="-1" className="accounts-results" aria-busy={loading}>
      {loading ? <p role="status" className="accounts-empty">Consultando cuentas…</p> : failure ? <><AccountFailure failure={failure} /><Button variant="secondary" onClick={reload}>Volver a consultar</Button></>
        : <>{data.data.length ? <AccountsTable accounts={data.data} /> : <div className="accounts-empty"><h2>No hay cuentas para estos filtros</h2><p>Prueba otro rol, estado o correo exacto.</p></div>}
          <nav className="accounts-pagination" aria-label="Paginación de cuentas"><p>{data.meta.total} cuentas · página {data.meta.current_page} de {data.meta.last_page}</p>
            <div><Button variant="secondary" disabled={data.meta.current_page <= 1} onClick={() => paginate(data.meta.current_page - 1)}>Anterior</Button>
              <Button variant="secondary" disabled={data.meta.current_page >= data.meta.last_page} onClick={() => paginate(data.meta.current_page + 1)}>Siguiente</Button></div></nav>
        </>}
    </div>
    <p className="accounts-footnote">El estado de acceso es independiente de la ficha del paciente, del registro profesional y de cualquier bloqueo temporal.</p>
  </section>;
}
