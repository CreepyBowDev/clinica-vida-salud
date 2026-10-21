import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PatientForm from '../components/PatientForm';
import PatientFailure from '../components/PatientFailure';
import PatientVersionReview from '../components/PatientVersionReview';
import { usePatientQuery } from '../hooks/usePatientQuery';
import { usePatientSubmission } from '../hooks/usePatientSubmission';
import { actualizarMiPerfil } from '../services/perfil.service';
import { borradorPaciente, cambiosPaciente } from '../utils/patientDraft';
import '../styles/patients.css';

export default function MyProfilePage() {
  const { data, loading, failure, reload } = usePatientQuery('profile');
  const [notice, setNotice] = useState(false);
  return <section className="workspace-page patients-page">
    <header className="page-heading"><h1>Mi perfil</h1><p>Tus datos de contacto y preferencias en Clínica Vida Salud.</p></header>
    {notice && <Alert variant="success"><p>Tu perfil se guardó correctamente.</p></Alert>}
    <div aria-busy={loading}>{loading ? <p role="status">Consultando tu perfil…</p> : failure ? <><PatientFailure failure={failure} /><Button onClick={reload}>Volver a consultar mi perfil</Button></> : <ProfileEditor initial={data} onSubmitting={() => setNotice(false)} onSaved={() => { setNotice(true); reload(); }} />}</div>
  </section>;
}

function ProfileEditor({ initial, onSubmitting, onSaved }) {
  const [base, setBase] = useState(initial);
  const [draft, setDraft] = useState(() => borradorPaciente(initial));
  const [emergency, setEmergency] = useState(Boolean(initial.contacto_emergencia));
  const [reminders, setReminders] = useState(initial.recordatorios_habilitados == null ? '' : String(initial.recordatorios_habilitados));
  const [confirm, setConfirm] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const { busy, failure, remaining, execute, report } = usePatientSubmission(form, { operation: 'profile' });
  const frozen = busy || blocked || confirm;
  function adopt(current) {
    setBase(current); setDraft(borradorPaciente(current)); setEmergency(Boolean(current.contacto_emergencia));
    setReminders(current.recordatorios_habilitados == null ? '' : String(current.recordatorios_habilitados));
    setConfirm(false); setBlocked(false); report(null);
  }
  async function submit(event) {
    event.preventDefault();
    if (busy || blocked || remaining) return;
    onSubmitting();
    const fields = {};
    for (const name of ['telefono', ...(emergency ? ['contacto_emergencia.nombre', 'contacto_emergencia.parentesco', 'contacto_emergencia.telefono'] : [])]) if (!draft[name].trim()) fields[name] = 'Completa este campo.';
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados para continuar.', fields }); return; }
    const { changes: all } = cambiosPaciente(base, draft, emergency);
    const changes = Object.fromEntries(Object.entries(all).filter(([name]) => ['telefono', 'correo_contacto', 'direccion', 'contacto_emergencia'].includes(name)));
    if (reminders !== '' && (base.recordatorios_habilitados == null || (reminders === 'true') !== base.recordatorios_habilitados)) changes.recordatorios_habilitados = reminders === 'true';
    if (!Object.keys(changes).length) { report({ message: 'No hay cambios para guardar.', fields: {} }); return; }
    if (!confirm && ((base.contacto_emergencia && !emergency) || changes.recordatorios_habilitados === false)) { setConfirm(true); return; }
    const response = await execute(() => actualizarMiPerfil({ ...changes, actualizado_en_original: base.actualizado_en }));
    if (response?.data) onSaved();
    if (response?.failure) { setConfirm(false); if (response.failure.stale || response.failure.uncertain) setBlocked(true); }
  }
  return <>
    <section className="patients-panel" aria-labelledby="profile-identity"><h2 id="profile-identity">Datos personales</h2>
      <dl className="patients-data"><div><dt>Nombre completo</dt><dd>{base.nombres} {base.apellidos}</dd></div><div><dt>Código de ficha</dt><dd>{base.codigo_paciente}</dd></div>
        <div><dt>CI</dt><dd>{base.documento_identidad || 'Sin CI registrado'}</dd></div><div><dt>Fecha de nacimiento</dt><dd><time dateTime={base.fecha_nacimiento}>{base.fecha_nacimiento.split('-').reverse().join('/')}</time></dd></div>
        <div><dt>Sexo</dt><dd>{base.sexo || 'No registrado'}</dd></div><div><dt>Estado de ficha</dt><dd>{base.activo ? 'Activa' : 'Inactiva'}</dd></div></dl>
      <p className="patients-note">Para corregir tu identidad, acude a Recepción con tu documento.</p>
      {!base.activo && <p className="patients-note">Tu ficha está inactiva y no admite nuevas reservas. Puedes actualizar contacto, emergencia y preferencias; conservas tu historial y tus citas existentes.</p>}
    </section>
    <form ref={form} className="patients-panel patient-form" aria-label="Actualizar mi perfil" aria-busy={busy} onSubmit={submit}>
      <PatientFailure failure={failure} remaining={remaining} />
      <PatientForm contactOnly draft={draft} onChange={(name, value) => setDraft({ ...draft, [name]: value })} emergency={emergency} onEmergencyChange={setEmergency} busy={frozen} fields={failure?.fields} />
      <fieldset className="patient-identity-edit" disabled={frozen}><legend>Recordatorios internos</legend><div className="field">
        <label htmlFor="profile-reminders">Preferencia de recordatorios</label>
        <select id="profile-reminders" value={reminders} onChange={event => setReminders(event.target.value)} aria-invalid={Boolean(failure?.fields.recordatorios_habilitados)} aria-describedby="profile-reminders-help">
          {base.recordatorios_habilitados == null && <option value="">Sin preferencia registrada</option>}<option value="true">Habilitados</option><option value="false">Deshabilitados</option>
        </select><p id="profile-reminders-help" className={failure?.fields.recordatorios_habilitados ? 'field-error' : 'field-hint'}>{failure?.fields.recordatorios_habilitados || 'Avisos dentro del portal; no se envían por correo ni SMS. Guardar esta preferencia no genera un aviso desde el navegador.'}</p>
      </div></fieldset>
      {confirm && <p role="status" className="patients-note">Confirma los cambios.{base.contacto_emergencia && !emergency ? ' Se retirará tu contacto de emergencia.' : ''}{reminders === 'false' && base.recordatorios_habilitados !== false ? ' Se deshabilitarán tus recordatorios internos.' : ''}</p>}
      {blocked && <PatientVersionReview profile onUse={adopt} />}
      <div className="patients-actions"><Button type="submit" loading={busy} disabled={blocked || remaining > 0}>{busy ? 'Guardando perfil…' : remaining > 0 ? `Espera ${remaining} s` : confirm ? 'Confirmar cambios de mi perfil' : 'Guardar mi perfil'}</Button>{confirm && <Button variant="quiet" disabled={busy} onClick={() => setConfirm(false)}>Volver a revisar cambios</Button>}</div>
    </form>
    <section className="patients-panel" aria-labelledby="profile-account"><h2 id="profile-account">Acceso a tu cuenta</h2><dl className="patients-data"><div><dt>Correo de acceso</dt><dd>{base.correo_acceso}</dd></div></dl>
      <p className="patients-note">Este es el correo con el que inicias sesión. Editar tu correo de contacto no lo modifica.</p><div className="patients-actions"><Link className="button button--secondary" to="/mi-perfil/correo">Cambiar correo de acceso</Link><Link className="button button--secondary" to="/mi-cuenta/contrasena">Cambiar contraseña</Link></div>
    </section>
  </>;
}
