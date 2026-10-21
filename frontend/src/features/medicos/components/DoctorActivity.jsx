import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import { useDoctorSubmission } from '../hooks/useDoctorSubmission';
import { cambiarEstadoMedico } from '../services/medico.service';
import DoctorData from './DoctorData';
import DoctorFailure from './DoctorFailure';
import DoctorVersionReview from './DoctorVersionReview';

export default function DoctorActivity({ initial, onSaved }) {
  const [base, setBase] = useState(initial);
  const [confirm, setConfirm] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const { busy, failure, remaining, execute, report } = useDoctorSubmission(form);
  async function submit(event) {
    event.preventDefault();
    if (!confirm || busy || blocked || remaining) return;
    const result = await execute(() => cambiarEstadoMedico(base.id_medico, { activo: !base.activo, actualizado_en_original: base.actualizado_en }));
    if (result?.data) onSaved();
    if (result?.failure) { setConfirm(false); if (result.failure.stale || result.failure.uncertain || result.failure.incompatible) setBlocked(true); }
  }
  return <div className="catalog-panel"><DoctorData doctor={base} />
    <p className="catalog-note">Inactivar impide nuevas citas y reprogramaciones a su favor; conserva historial y obligaciones anteriores autorizadas. No desactiva su cuenta ni cierra sesiones. Activar no cambia la cuenta, el rol, los bloqueos ni la actividad del catálogo y no genera turnos disponibles.</p>
    <form ref={form} aria-label="Cambiar actividad profesional" aria-busy={busy} onSubmit={submit}>
      <DoctorFailure failure={failure} remaining={remaining} />
      {failure?.incompatible && <p className="catalog-note"><Link to="/administracion/cuentas">Consultar cuentas de usuario</Link></p>}
      {blocked && <DoctorVersionReview id={base.id_medico} stateOnly onUse={current => { setBase(current); setBlocked(false); setConfirm(false); report(null); }} />}
      {confirm && <section className="catalog-confirm" aria-labelledby="doctor-activity-confirm"><h2 id="doctor-activity-confirm">{base.activo ? 'Confirmar inactivación profesional' : 'Confirmar activación profesional'}</h2>
        <p>{base.nombres} {base.apellidos} · {base.codigo_medico}</p><p>{base.activo ? 'Se impedirá asignarle nuevas citas. Se conservan las citas existentes y la atención previa autorizada.' : 'Se habilitará la actividad profesional si el servidor valida el vínculo de cuenta. No se activará ninguna cuenta ni especialidad.'}</p></section>}
      <div className="catalog-actions">{confirm ? <><Button type="submit" loading={busy} disabled={remaining > 0}>{busy ? 'Guardando actividad…' : 'Confirmar cambio de actividad'}</Button><Button variant="quiet" disabled={busy} onClick={() => setConfirm(false)}>Volver a revisar</Button></> : <Button variant="secondary" disabled={busy || blocked || remaining > 0} onClick={() => setConfirm(true)}>{base.activo ? 'Inactivar profesional' : 'Activar profesional'}</Button>}
        {!busy && !confirm && <><Link className="button button--secondary" to={`/administracion/medicos/${base.id_medico}/editar`}>Editar datos profesionales</Link>
          <Link className="button button--secondary" to={`/administracion/medicos/${base.id_medico}/especialidades`}>Gestionar habilitaciones</Link></>}
        {!busy && !confirm && !base.cuenta_vinculada && <Link className="button button--secondary" to={`/administracion/cuentas/nueva?id_medico=${base.id_medico}`}>Crear cuenta para este médico</Link>}
      </div>
    </form>
  </div>;
}
