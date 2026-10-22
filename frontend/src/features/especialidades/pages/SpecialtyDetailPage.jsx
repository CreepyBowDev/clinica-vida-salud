import { useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PageHeading from '../../../components/PageHeading';
import QueryMessage from '../../../components/QueryMessage';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarEspecialidad, cambiarEstadoEspecialidad } from '../services/especialidad.service';
import { useSpecialtySubmission } from '../hooks/useSpecialtySubmission';
import SpecialtyData from '../components/SpecialtyData';
import SpecialtyFailure from '../components/SpecialtyFailure';
import SpecialtyVersionReview from '../components/SpecialtyVersionReview';
import '../../../styles/catalogs.css';

const fields = [];
export default function SpecialtyDetailPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Especialidad no disponible</h1><Link to="/administracion/especialidades">Volver al catálogo</Link></section>;
  return <Detail key={id} id={id} />;
}
function Detail({ id }) {
  const query = useCatalogQuery(consultarEspecialidad, id, fields);
  const location = useLocation();
  const [notice, setNotice] = useState(location.state?.specialtyNotice || '');
  return <section className="workspace-page catalog-page query-detail"><Link className="back-link" to="/administracion/especialidades">Volver al catálogo</Link>
    <PageHeading title="Detalle de especialidad" description="El estado del catálogo es independiente de la actividad profesional y de las cuentas de acceso." />
    {notice && <Alert variant="success">{notice}</Alert>}
    {query.loading ? <QueryMessage loading title="Consultando especialidad…" /> : query.failure ? <div className="query-failure"><SpecialtyFailure failure={query.failure} remaining={query.remaining} /><Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></div> : <SpecialtyState initial={query.data} onSaved={() => { setNotice('Estado actualizado. Se consultó nuevamente la especialidad.'); query.reload(); }} />}
  </section>;
}
function SpecialtyState({ initial, onSaved }) {
  const [base, setBase] = useState(initial);
  const [confirm, setConfirm] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const { busy, failure, remaining, execute, report } = useSpecialtySubmission(form);
  async function submit(event) {
    event.preventDefault();
    if (!confirm || busy || blocked || remaining) return;
    const result = await execute(() => cambiarEstadoEspecialidad(base.id_especialidad, { activa: !base.activa, actualizado_en_original: base.actualizado_en }));
    if (result?.data) onSaved();
    if (result?.failure) { setConfirm(false); if (result.failure.stale || result.failure.uncertain) setBlocked(true); }
  }
  return <div className="catalog-panel"><SpecialtyData specialty={base} />
    <p className="catalog-note">Inactivar conserva las asociaciones, citas e historial; impide ofrecer la especialidad para nuevos turnos. No cancela ni reprograma citas existentes. Activar no genera disponibilidad.</p>
    <form ref={form} aria-label="Cambiar estado de especialidad" onSubmit={submit} aria-busy={busy}>
      <SpecialtyFailure failure={failure} remaining={remaining} />
      {blocked && <SpecialtyVersionReview id={base.id_especialidad} stateOnly onUse={current => { setBase(current); setBlocked(false); setConfirm(false); report(null); }} />}
      {confirm && <ConfirmationPanel title={base.activa ? 'Confirmar inactivación' : 'Confirmar activación'}><p>{base.activa ? 'La especialidad dejará de ofrecerse para nuevos turnos. Se conserva el historial.' : 'Se habilitará el catálogo, sin modificar actividad de médicos, cuentas ni horarios.'}</p></ConfirmationPanel>}
      <ActionGroup className="form-actions">{confirm ? <><Button type="submit" variant={base.activa ? 'danger' : 'primary'} loading={busy} disabled={remaining > 0}>{busy ? 'Guardando estado…' : 'Confirmar cambio de estado'}</Button><Button variant="quiet" disabled={busy} onClick={() => setConfirm(false)}>Volver a revisar</Button></> : <Button variant={base.activa ? 'danger' : 'secondary'} disabled={busy || blocked || remaining > 0} onClick={() => setConfirm(true)}>{base.activa ? 'Inactivar especialidad' : 'Activar especialidad'}</Button>}
        {!busy && !confirm && <Link className="button button--secondary" to={`/administracion/especialidades/${base.id_especialidad}/editar`}>Editar especialidad</Link>}
      </ActionGroup>
    </form>
  </div>;
}
