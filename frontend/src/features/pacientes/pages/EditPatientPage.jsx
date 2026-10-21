import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import PatientForm from '../components/PatientForm';
import PatientFailure from '../components/PatientFailure';
import PatientVersionReview from '../components/PatientVersionReview';
import { usePatientQuery } from '../hooks/usePatientQuery';
import { usePatientSubmission } from '../hooks/usePatientSubmission';
import { actualizarPaciente } from '../services/paciente.service';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { borradorPaciente, cambiosPaciente } from '../utils/patientDraft';
import '../styles/patients.css';

export default function EditPatientPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Ficha no disponible</h1><Link to="/recepcion/pacientes">Volver a pacientes</Link></section>;
  return <EditPatient key={id} id={id} />;
}
function EditPatient({ id }) {
  const { data, loading, failure, reload } = usePatientQuery('detail', id);
  return <section className="workspace-page patients-page"><Link className="patients-back" to={`/recepcion/pacientes/${id}`}>Volver a la ficha</Link>
    <header className="page-heading"><h1>Editar ficha administrativa</h1><p>Conserva la identidad y el historial del mismo paciente.</p></header>
    {loading ? <p role="status">Consultando ficha…</p> : failure ? <><PatientFailure failure={failure} /><Button onClick={reload}>Volver a consultar</Button></> : <PatientEditor initial={data} />}
  </section>;
}
function PatientEditor({ initial }) {
  const [base, setBase] = useState(initial);
  const [draft, setDraft] = useState(() => borradorPaciente(initial));
  const [emergency, setEmergency] = useState(Boolean(initial.contacto_emergencia));
  const [identity, setIdentity] = useState(false);
  const [reason, setReason] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const navigate = useNavigate();
  const { busy, failure, remaining, execute, report } = usePatientSubmission(form, { operation: 'edit' });
  const { changes, identityChanged } = cambiosPaciente(base, draft, emergency);
  const ciChanged = Object.hasOwn(changes, 'documento_identidad');
  const frozen = busy || blocked || confirm;
  function adopt(current) { setBase(current); setDraft(borradorPaciente(current)); setEmergency(Boolean(current.contacto_emergencia)); setIdentity(false); setReason(''); setConfirm(false); setBlocked(false); report(null); }
  async function submit(event) {
    event.preventDefault();
    if (busy || blocked || remaining) return;
    const fields = {};
    for (const name of ['nombres', 'apellidos', 'fecha_nacimiento', 'telefono', ...(emergency ? ['contacto_emergencia.nombre', 'contacto_emergencia.parentesco', 'contacto_emergencia.telefono'] : [])]) if (!draft[name].trim()) fields[name] = 'Completa este campo.';
    if (base.documento_identidad && !draft.documento_identidad.trim()) fields.documento_identidad = 'No se puede retirar un CI informado.';
    if (/\p{C}/u.test(draft.documento_identidad)) fields.documento_identidad = 'El CI no puede contener caracteres de control.';
    if (identityChanged && (!reason.trim() || /\p{C}/u.test(reason))) fields.motivo = 'Introduce un motivo administrativo sin caracteres de control.';
    if (identityChanged && !identity) fields.identidad_verificada = 'Comprueba presencialmente la identidad antes de corregirla.';
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados para continuar.', fields }); return; }
    if (!Object.keys(changes).length) { report({ message: 'No hay cambios para guardar.', fields: {} }); return; }
    if ((identityChanged || (base.contacto_emergencia && !emergency)) && !confirm) { setConfirm(true); return; }
    const response = await execute(() => actualizarPaciente(base.id_paciente, { ...changes, actualizado_en_original: base.actualizado_en,
      ...(identityChanged ? { motivo: reason.trim(), identidad_verificada: true } : {}) }));
    if (response?.data) navigate(`/recepcion/pacientes/${base.id_paciente}`, { replace: true, state: { patientNotice: 'Cambios administrativos guardados. La ficha se consultó nuevamente.' } });
    if (response?.failure) { setConfirm(false); if (response.failure.stale || response.failure.uncertain) setBlocked(true); }
  }
  return <form ref={form} className="patients-panel patient-form" aria-label="Editar ficha administrativa" aria-busy={busy} onSubmit={submit}>
    <PatientFailure failure={failure} remaining={remaining} />
    <p>{base.codigo_paciente} · {base.nombres} {base.apellidos}</p>
    <PatientForm draft={draft} editing documentRequired={Boolean(base.documento_identidad)} onChange={(name, value) => { setDraft({ ...draft, [name]: value }); setIdentity(false); }} emergency={emergency} onEmergencyChange={setEmergency} busy={frozen} fields={failure?.fields} />
    {identityChanged && <fieldset className="patient-identity-edit" disabled={frozen}><legend>Corrección de identidad</legend>
      <FormField label="Motivo de la corrección" required maxLength={500} value={reason} onChange={event => setReason(event.target.value)} error={failure?.fields.motivo} />
      <div className="patient-checkbox"><label><input type="checkbox" required checked={identity} onChange={event => setIdentity(event.target.checked)} aria-invalid={Boolean(failure?.fields.identidad_verificada)} aria-describedby="identity-edit-help" />He comprobado presencialmente la identidad y los datos corregidos.</label><p id="identity-edit-help" className="field-hint">{failure?.fields.identidad_verificada || 'Las correcciones conservan el identificador, la cuenta vinculada y el historial.'}</p></div>
      {ciChanged && <p className="patients-note">Cambiar el CI invalida los códigos de Recepción y los procesos de vinculación pendientes. Conserva la cuenta ya vinculada y sus credenciales.</p>}
    </fieldset>}
    {confirm && <p role="status" className="patients-note">Revisa la corrección antes de confirmar.{ciChanged ? ' Se invalidarán códigos y vinculaciones pendientes por el cambio efectivo de CI.' : ''}{base.contacto_emergencia && !emergency ? ' Se retirará el contacto de emergencia.' : ''}</p>}
    {blocked && <PatientVersionReview id={base.id_paciente} onUse={adopt} />}
    <div className="patients-actions"><Button type="submit" loading={busy} disabled={blocked || remaining > 0}>{busy ? 'Guardando cambios…' : remaining > 0 ? `Espera ${remaining} s` : confirm ? 'Confirmar cambios administrativos' : 'Guardar cambios'}</Button>{confirm && <Button variant="quiet" disabled={busy} onClick={() => setConfirm(false)}>Volver a revisar cambios</Button>}</div>
  </form>;
}
