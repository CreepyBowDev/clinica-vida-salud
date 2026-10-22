import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import FormSection from '../../../components/FormSection';
import ActionGroup from '../../../components/ActionGroup';
import { actualizarMedico, crearMedico, camposMedico } from '../services/medico.service';
import { useDoctorSubmission } from '../hooks/useDoctorSubmission';
import DoctorFailure from './DoctorFailure';
import DoctorVersionReview from './DoctorVersionReview';
import InitialSpecialties from './InitialSpecialties';

const controls = [
  ['registro_profesional', 'Registro profesional', 80], ['nombres', 'Nombres', 120], ['apellidos', 'Apellidos', 120],
  ['telefono', 'Teléfono', 40], ['correo_contacto', 'Correo de contacto', 254],
];
const draftFrom = data => Object.fromEntries(camposMedico.map(key => [key, data?.[key] || '']));
export default function DoctorEditor({ initial }) {
  const [base, setBase] = useState(initial);
  const [draft, setDraft] = useState(() => draftFrom(initial));
  const [selected, setSelected] = useState([]);
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const navigate = useNavigate();
  const { busy, failure, remaining, execute, report } = useDoctorSubmission(form, !initial);
  async function submit(event) {
    event.preventDefault();
    if (busy || blocked || remaining) return;
    const fields = {};
    for (const [key, , max] of controls) if (!draft[key].trim() || draft[key].trim().length > max || /\p{C}/u.test(draft[key])) fields[key] = `Completa este campo con un máximo de ${max} caracteres, sin caracteres de control.`;
    if (draft.correo_contacto.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(draft.correo_contacto.trim())) fields.correo_contacto = 'Introduce un correo electrónico válido.';
    if (!base && !selected.length) fields.especialidades = 'Selecciona al menos una especialidad inicial.';
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados para continuar.', fields }); return; }
    const data = Object.fromEntries(camposMedico.map(key => [key, key === 'correo_contacto' ? draft[key].trim().toLowerCase() : draft[key].trim()]));
    if (base && camposMedico.every(key => data[key] === base[key])) { report({ message: 'No hay cambios para guardar.', fields: {} }); return; }
    const result = await execute(() => base ? actualizarMedico(base.id_medico, { ...data, actualizado_en_original: base.actualizado_en }) : crearMedico({ ...data, especialidades: selected.map(item => item.id_especialidad) }));
    if (result?.data) navigate(`/administracion/medicos/${result.data.id_medico}`, { replace: true, state: { doctorNotice: base ? 'Datos profesionales guardados. Se consultó nuevamente el registro.' : 'Médico registrado. Se consultó nuevamente el registro.' } });
    if (result?.failure?.stale || result?.failure?.uncertain) setBlocked(true);
  }
  return <form ref={form} className="catalog-panel catalog-editor admin-form" aria-label={initial ? 'Editar médico' : 'Registrar médico'} noValidate aria-busy={busy} onSubmit={submit}>
    <DoctorFailure failure={failure} remaining={remaining} />
    <FormSection title="Datos profesionales" disabled={busy || blocked}>
      <div className="form-field-grid">{controls.slice(0, 3).map(([key, label, max]) => <FormField key={key} label={label} required maxLength={max} value={draft[key]} onChange={event => setDraft({ ...draft, [key]: event.target.value })} error={failure?.fields[key]} />)}</div>
    </FormSection>
    <FormSection title="Contacto profesional" disabled={busy || blocked}>
      <div className="form-field-grid">{controls.slice(3).map(([key, label, max]) => <FormField key={key} label={label} required maxLength={max} type={key === 'correo_contacto' ? 'email' : 'tel'} value={draft[key]} onChange={event => setDraft({ ...draft, [key]: event.target.value })} error={failure?.fields[key]} />)}</div>
      <p className="catalog-note">El correo de contacto no cambia el correo de acceso. El registro profesional y este correo no son identificadores únicos.</p>
    </FormSection>
    {!initial && <FormSection title="Habilitación inicial" disabled={busy || blocked}><InitialSpecialties selected={selected} onChange={setSelected} error={failure?.fields.especialidades} /></FormSection>}
    {blocked && base && <DoctorVersionReview id={base.id_medico} onUse={current => { setBase(current); setDraft(draftFrom(current)); setBlocked(false); report(null); }} />}
    {!initial && <p className="catalog-note">El servidor genera el código profesional. El alta no crea una cuenta de acceso, consultorio ni horarios.</p>}
    <ActionGroup className="form-actions"><Button type="submit" loading={busy} disabled={blocked || remaining > 0}>{busy ? 'Guardando…' : initial ? 'Guardar datos profesionales' : 'Registrar médico'}</Button>
      <Link className="button button--secondary" to={base ? `/administracion/medicos/${base.id_medico}` : '/administracion/medicos'}>{blocked && !base ? 'Consultar médicos' : 'Volver sin guardar'}</Link></ActionGroup>
  </form>;
}
