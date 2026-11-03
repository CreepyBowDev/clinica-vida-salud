import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ROLES } from '../../../constants/roles';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Pagination from '../../../components/Pagination';
import PageHeading from '../../../components/PageHeading';
import ActionGroup from '../../../components/ActionGroup';
import QueryMessage from '../../../components/QueryMessage';
import AccountsTable from '../components/AccountsTable';
import AccountFailure from '../components/AccountFailure';
import { useAccountQuery } from '../hooks/useAccountQuery';
import '../styles/accounts.css';
import '../../../styles/directory.css';

const empty = { rol: '', estado_cuenta: '', correo_acceso: '' };
export default function AccountsPage() {
  const [draft, setDraft] = useState(empty);
  const [filters, setFilters] = useState({ ...empty, page: 1 });
  const { data, failure, loading, reload } = useAccountQuery('list', filters);
  const results = useRef(null);
  const field = name => ({ value: draft[name], onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: failure?.fields[name] });
  const paginate = page => { setFilters({ ...filters, page }); results.current?.focus(); };

  return <section className="workspace-page accounts-page directory-page">
    <PageHeading title="Cuentas de usuario" description="Consulta el acceso del personal y las cuentas verificadas de pacientes." actions={<Link className="button button--primary" to="/administracion/cuentas/nueva">Crear cuenta del personal</Link>} />
    <form className="accounts-filters directory-filters" aria-label="Filtrar cuentas" onSubmit={event => {
      event.preventDefault(); setFilters({ ...draft, correo_acceso: draft.correo_acceso.trim(), page: 1 });
    }}>
      <SelectField label="Rol" {...field('rol')}><option value="">Todos los roles</option>{Object.values(ROLES).map(rol => <option key={rol}>{rol}</option>)}</SelectField>
      <SelectField label="Estado de cuenta" {...field('estado_cuenta')}><option value="">Todos los estados</option>{['Pendiente', 'Activa', 'Desactivada'].map(state => <option key={state}>{state}</option>)}</SelectField>
      <FormField label="Correo de acceso exacto" type="email" maxLength={254} autoComplete="off" {...field('correo_acceso')} />
       <ActionGroup><Button type="submit" variant="secondary">Aplicar filtros</Button><Button variant="quiet" onClick={() => { setDraft(empty); setFilters({ ...empty, page: 1 }); reload(); }}>Limpiar</Button></ActionGroup>
    </form>
    <div ref={results} tabIndex="-1" className="accounts-results directory-results" aria-busy={loading}>
      {loading ? <QueryMessage loading title="Consultando cuentas…" /> : failure ? <div className="query-failure"><AccountFailure failure={failure} /><Button variant="secondary" onClick={reload}>Volver a consultar</Button></div>
        : <><header className="directory-summary"><h2>Resultados</h2><p><strong>{data.meta.total}</strong> {data.meta.total === 1 ? 'cuenta encontrada' : 'cuentas encontradas'}</p></header>
          {data.data.length ? <AccountsTable accounts={data.data} /> : <QueryMessage title="No hay cuentas para estos filtros" description="Prueba otro rol, estado o correo exacto." />}
          <Pagination meta={data.meta} label="cuentas" onPage={paginate} />
        </>}
    </div>
    <p className="accounts-footnote">El estado de acceso es independiente de la ficha del paciente, del registro profesional y de cualquier bloqueo temporal.</p>
  </section>;
}
