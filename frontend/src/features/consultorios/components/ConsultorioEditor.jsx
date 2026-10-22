import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import FormSection from '../../../components/FormSection';
import ActionGroup from '../../../components/ActionGroup';
import { useConsultorioSubmission } from '../hooks/useConsultorioSubmission';
import { actualizarConsultorio, crearConsultorio } from '../services/consultorio.service';
import ConsultorioFailure from './ConsultorioFailure';
import ConsultorioVersionReview from './ConsultorioVersionReview';

export default function ConsultorioEditor({ initial }) {
  const [base, setBase] = useState(initial);
  const [draft, setDraft] = useState({ codigo_consultorio: initial?.codigo_consultorio || '', nombre: initial?.nombre || '' });
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const navigate = useNavigate();
  const { busy, failure, remaining, execute, report } = useConsultorioSubmission(form, !initial);
  async function submit(event) {
    event.preventDefault();
    if (busy || blocked || remaining) return;
    const fields = {};
    for (const [key, limit] of [['codigo_consultorio', 50], ['nombre', 120]]) {
      if (!draft[key].trim() || [...draft[key].trim()].length > limit || /\p{C}/u.test(draft[key])) fields[key] = `Introduce un valor de hasta ${limit} caracteres, sin caracteres de control.`;
    }
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados para continuar.', fields }); return; }
    const data = { codigo_consultorio: draft.codigo_consultorio.trim(), nombre: draft.nombre.trim() };
    if (base && data.codigo_consultorio === base.codigo_consultorio && data.nombre === base.nombre) { report({ message: 'No hay cambios para guardar.', fields: {} }); return; }
    const result = await execute(() => base ? actualizarConsultorio(base.id_consultorio, { ...data, actualizado_en_original: base.actualizado_en }) : crearConsultorio(data));
    if (result?.data) navigate(`/administracion/consultorios/${result.data.id_consultorio}`, { replace: true, state: { consultorioNotice: base ? 'Cambios guardados. Se consultó nuevamente el consultorio.' : 'Consultorio registrado. Se consultó nuevamente el registro.' } });
    if (result?.failure?.stale || result?.failure?.uncertain) setBlocked(true);
  }
  return <form ref={form} className="catalog-panel catalog-editor admin-form" aria-label={initial ? 'Editar consultorio' : 'Registrar consultorio'} aria-busy={busy} onSubmit={submit} noValidate>
    <ConsultorioFailure failure={failure} remaining={remaining} />
    <FormSection title="Datos del consultorio" disabled={busy || blocked}>
      <FormField label="Código" required maxLength={50} autoComplete="off" value={draft.codigo_consultorio} onChange={event => setDraft({ ...draft, codigo_consultorio: event.target.value })} error={failure?.fields.codigo_consultorio} hint="Identifica el consultorio dentro de la sede. Se conserva la escritura del código." />
      <FormField label="Nombre" required maxLength={120} autoComplete="off" value={draft.nombre} onChange={event => setDraft({ ...draft, nombre: event.target.value })} error={failure?.fields.nombre} />
    </FormSection>
    <p className="catalog-note">Cambiar el código o nombre no modifica la ubicación guardada en las citas. Los cambios de asignación se gestionan por separado.</p>
    {blocked && base && <ConsultorioVersionReview id={base.id_consultorio} onUse={current => {
      setBase(current); setDraft({ codigo_consultorio: current.codigo_consultorio, nombre: current.nombre }); setBlocked(false); report(null);
    }} />}
    <ActionGroup className="form-actions"><Button type="submit" loading={busy} disabled={blocked || remaining > 0}>{busy ? 'Guardando…' : initial ? 'Guardar cambios' : 'Registrar consultorio'}</Button>
      <Link to={base ? `/administracion/consultorios/${base.id_consultorio}` : '/administracion/consultorios'} className="button button--secondary">{blocked && !base ? 'Consultar catálogo' : 'Volver sin guardar'}</Link></ActionGroup>
  </form>;
}
