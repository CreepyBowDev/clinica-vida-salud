import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import PatientForm from '../components/PatientForm';
import PatientDuplicateReview from '../components/PatientDuplicateReview';
import PatientFailure from '../components/PatientFailure';
import { crearPaciente } from '../services/paciente.service';
import { usePatientSubmission } from '../hooks/usePatientSubmission';
import '../styles/patients.css';

const empty = { documento_identidad: '', nombres: '', apellidos: '', fecha_nacimiento: '', sexo: '', telefono: '', correo_contacto: '', direccion: '', 'contacto_emergencia.nombre': '', 'contacto_emergencia.parentesco': '', 'contacto_emergencia.telefono': '' };
export default function NewPatientPage() {
  const [draft, setDraft] = useState(empty);
  const [emergency, setEmergency] = useState(false);
  const [review, setReview] = useState(null);
  const [reviewComplete, setReviewComplete] = useState(false);
  const [reviewed, setReviewed] = useState(false);
  const [uncertain, setUncertain] = useState(false);
  const [result, setResult] = useState(null);
  const form = useRef(null);
  const heading = useRef(null);
  const attempt = useRef(0);
  const { busy, failure, remaining, execute, report } = usePatientSubmission(form);
  const noDocument = !draft.documento_identidad.trim();
  useEffect(() => { if (result) heading.current?.focus(); }, [result]);
  function change(name, value) {
    setDraft(current => ({ ...current, [name]: value }));
    if (['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento'].includes(name)) { setReview(null); setReviewComplete(false); setReviewed(false); }
  }
  function search(byDocument) {
    const names = { nombres: draft.nombres.trim(), apellidos: draft.apellidos.trim() };
    if (!byDocument && (!names.nombres || !names.apellidos)) { report({ message: 'Introduce nombres y apellidos para buscar posibles fichas.', fields: { nombres: !names.nombres ? 'Introduce los nombres.' : undefined, apellidos: !names.apellidos ? 'Introduce los apellidos.' : undefined } }); return; }
    setReviewComplete(false); setReviewed(false);
    setReview({ filters: byDocument ? { documento_identidad: draft.documento_identidad.trim() } : names, attempt: ++attempt.current });
  }
  async function submit(event) {
    event.preventDefault();
    if (busy || remaining || (uncertain && !reviewComplete)) return;
    const fields = {};
    for (const name of ['nombres', 'apellidos', 'fecha_nacimiento', 'telefono', ...(emergency ? ['contacto_emergencia.nombre', 'contacto_emergencia.parentesco', 'contacto_emergencia.telefono'] : [])]) {
      if (!draft[name].trim()) fields[name] = 'Completa este campo.';
    }
    if (/\p{C}/u.test(draft.documento_identidad)) fields.documento_identidad = 'El CI no puede contener caracteres de control.';
    if (noDocument && (!reviewComplete || !reviewed)) fields.posibles_duplicados_revisados = 'Busca las posibles fichas previas y confirma que las revisaste antes de registrar sin CI.';
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados para continuar.', fields }); return; }
    const response = await execute(() => crearPaciente({ ...draft, posibles_duplicados_revisados: reviewed,
      contacto_emergencia: emergency ? { nombre: draft['contacto_emergencia.nombre'], parentesco: draft['contacto_emergencia.parentesco'], telefono: draft['contacto_emergencia.telefono'] } : null }));
    if (response?.data) { setResult(response.data); setDraft(empty); setReview(null); }
    if (response?.failure?.uncertain) { setUncertain(true); setReview(null); setReviewComplete(false); setReviewed(false); }
  }
  return <section className="workspace-page patients-page">
    <Link className="patients-back" to="/recepcion/pacientes">Volver a pacientes</Link>
    <header className="page-heading"><h1 ref={heading} tabIndex="-1">{result ? 'Ficha de paciente creada' : 'Registrar paciente'}</h1><p>{result ? 'El registro administrativo fue confirmado por el servidor.' : 'Registra la ficha administrativa después de revisar posibles registros previos.'}</p></header>
    {result ? <div className="patients-panel"><Alert variant="success"><p>Se creó la ficha de {result.nombres} {result.apellidos}.</p></Alert><dl className="patients-data"><div><dt>Código de ficha</dt><dd>{result.codigo_paciente}</dd></div><div><dt>CI</dt><dd>{result.documento_identidad || 'Sin CI registrado'}</dd></div></dl>
      <p className="patients-note">Esta operación no crea una cuenta web ni genera un código de activación.</p>
      <Link className="button button--primary" to={`/recepcion/pacientes/${result.id_paciente}`}>Ver ficha creada</Link></div>
      : <form ref={form} className="patients-panel patient-form" aria-label="Registrar paciente" aria-busy={busy} onSubmit={submit}>
        <PatientFailure failure={failure} remaining={remaining} />
        <PatientForm draft={draft} onChange={change} emergency={emergency} onEmergencyChange={setEmergency} busy={busy} fields={failure?.fields} />
        <section className="patient-review" aria-labelledby="patient-review-heading"><h2 id="patient-review-heading">Revisar posibles fichas previas</h2>
          <p>Busca por CI exacto o por nombres y apellidos, incluyendo fichas inactivas. La semejanza de datos no prueba identidad ni autoriza fusionar registros.</p>
          <div className="patients-actions">{!noDocument && <Button variant="secondary" disabled={busy} onClick={() => search(true)}>Buscar por CI</Button>}<Button variant="secondary" disabled={busy} onClick={() => search(false)}>Buscar por nombres y apellidos</Button></div>
          {review && <PatientDuplicateReview key={review.attempt} filters={review.filters} onReady={setReviewComplete} />}
          {noDocument && <div className="patient-checkbox"><label><input type="checkbox" checked={reviewed} disabled={busy || !reviewComplete} aria-invalid={Boolean(failure?.fields.posibles_duplicados_revisados)} aria-describedby="patient-review-help" onChange={event => setReviewed(event.target.checked)} />He revisado las posibles fichas previas antes de registrar sin CI.</label>
            <p id="patient-review-help" className={failure?.fields.posibles_duplicados_revisados ? 'field-error' : 'field-hint'}>{failure?.fields.posibles_duplicados_revisados || 'Primero consulta las posibles fichas. Si encuentras la ficha del paciente, utiliza ese mismo registro.'}</p></div>}
        </section>
        {uncertain && <p className="patients-note" role="status">El resultado del envío anterior sigue sin confirmarse. Revisa las fichas encontradas; si ya existe la del paciente, ábrela y evita otra alta. Debes volver a consultar antes de habilitar un nuevo envío.</p>}
        <div className="patients-actions"><Button type="submit" loading={busy} disabled={remaining > 0 || (uncertain && !reviewComplete)}>{busy ? 'Registrando paciente…' : remaining > 0 ? `Espera ${remaining} s` : 'Crear ficha administrativa'}</Button></div>
        <p className="patients-note">El CI es obligatorio para el registro o vinculación web. Una ficha administrativa sin CI conserva las operaciones presenciales autorizadas; el documento podrá incorporarse después a esa misma ficha.</p>
      </form>}
  </section>;
}
