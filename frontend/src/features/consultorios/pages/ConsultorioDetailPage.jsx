import { useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarConsultorio, cambiarEstadoConsultorio } from '../services/consultorio.service';
import { useConsultorioSubmission } from '../hooks/useConsultorioSubmission';
import ConsultorioData from '../components/ConsultorioData';
import ConsultorioFailure from '../components/ConsultorioFailure';
import ConsultorioVersionReview from '../components/ConsultorioVersionReview';
import '../../../styles/catalogs.css';

const fields = [];
export default function ConsultorioDetailPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Consultorio no disponible</h1><Link to="/administracion/consultorios">Volver al catálogo</Link></section>;
  return <Detail key={id} id={id} />;
}
function Detail({ id }) {
  const query = useCatalogQuery(consultarConsultorio, id, fields);
  const location = useLocation();
  const [notice, setNotice] = useState(location.state?.consultorioNotice || '');
  return <section className="workspace-page catalog-page"><Link to="/administracion/consultorios">Volver al catálogo</Link>
    <header className="page-heading"><h1>Detalle de consultorio</h1><p>Consulta sus datos y las cantidades administrativas vigentes.</p></header>
    {notice && <Alert variant="success">{notice}</Alert>}
    {query.loading ? <p role="status">Consultando consultorio…</p> : query.failure ? <><ConsultorioFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></>
      : <ConsultorioActivity initial={query.data} onSaved={() => { setNotice('Estado actualizado. Se consultó nuevamente el consultorio.'); query.reload(); }} />}
  </section>;
}
function ConsultorioActivity({ initial, onSaved }) {
  const [base, setBase] = useState(initial);
  const [confirm, setConfirm] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const { busy, failure, remaining, execute, report } = useConsultorioSubmission(form);
  async function submit(event) {
    event.preventDefault();
    if (!confirm || busy || blocked || remaining) return;
    const result = await execute(() => cambiarEstadoConsultorio(base.id_consultorio, { activo: !base.activo, actualizado_en_original: base.actualizado_en }));
    if (result?.data) onSaved();
    if (result?.failure) { setConfirm(false); if (result.failure.stale || result.failure.uncertain) setBlocked(true); }
  }
  return <div className="catalog-panel"><ConsultorioData consultorio={base} />
    <p className="catalog-note">No se puede desactivar mientras haya médicos asignados, incluidos los inactivos, o citas vigentes. Reactivar no activa médicos ni horarios ni crea disponibilidad.</p>
    <form ref={form} aria-label="Cambiar estado de consultorio" onSubmit={submit} aria-busy={busy}>
      <ConsultorioFailure failure={failure} remaining={remaining} />
      {blocked && <ConsultorioVersionReview id={base.id_consultorio} stateOnly onUse={current => { setBase(current); setBlocked(false); setConfirm(false); report(null); }} />}
      {confirm && <section className="catalog-confirm" aria-labelledby="consultorio-confirm-title"><h2 id="consultorio-confirm-title">{base.activo ? 'Confirmar desactivación' : 'Confirmar reactivación'}</h2><p>{base.activo ? 'Se desactivará el consultorio solo si el servidor confirma que no tiene asignaciones ni citas vigentes. No se trasladará ni cancelará ninguna cita.' : 'Se habilitará el consultorio, sin modificar médicos, horarios ni citas existentes.'}</p></section>}
      <div className="catalog-actions">{confirm ? <><Button type="submit" loading={busy} disabled={remaining > 0}>{busy ? 'Guardando estado…' : 'Confirmar cambio de estado'}</Button><Button variant="quiet" disabled={busy} onClick={() => setConfirm(false)}>Volver a revisar</Button></>
        : <Button variant="secondary" disabled={busy || blocked || remaining > 0} onClick={() => setConfirm(true)}>{base.activo ? 'Desactivar consultorio' : 'Reactivar consultorio'}</Button>}
        {!busy && !confirm && <Link className="button button--secondary" to={`/administracion/consultorios/${base.id_consultorio}/editar`}>Editar consultorio</Link>}
      </div>
    </form>
  </div>;
}
