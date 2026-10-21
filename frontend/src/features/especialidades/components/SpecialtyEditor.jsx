import { useId, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import { useSpecialtySubmission } from '../hooks/useSpecialtySubmission';
import { actualizarEspecialidad, crearEspecialidad } from '../services/especialidad.service';
import SpecialtyFailure from './SpecialtyFailure';
import SpecialtyVersionReview from './SpecialtyVersionReview';

export default function SpecialtyEditor({ initial }) {
  const [base, setBase] = useState(initial);
  const [draft, setDraft] = useState({ nombre: initial?.nombre || '', descripcion: initial?.descripcion || '' });
  const [blocked, setBlocked] = useState(false);
  const form = useRef(null);
  const descriptionId = useId();
  const navigate = useNavigate();
  const { busy, failure, remaining, execute, report } = useSpecialtySubmission(form, !initial);
  const frozen = busy || blocked;
  async function submit(event) {
    event.preventDefault();
    if (busy || blocked || remaining) return;
    const fields = {};
    if (!draft.nombre.trim() || draft.nombre.trim().length > 120 || /\p{C}/u.test(draft.nombre)) fields.nombre = 'Introduce un nombre de hasta 120 caracteres, sin caracteres de control.';
    if (draft.descripcion.length > 2000 || /\p{C}/u.test(draft.descripcion)) fields.descripcion = 'Introduce una descripción de hasta 2000 caracteres, sin saltos de línea ni caracteres de control.';
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados para continuar.', fields }); return; }
    const data = { nombre: draft.nombre.trim(), descripcion: draft.descripcion.trim() || null };
    if (base && data.nombre === base.nombre && data.descripcion === base.descripcion) { report({ message: 'No hay cambios para guardar.', fields: {} }); return; }
    const result = await execute(() => base
      ? actualizarEspecialidad(base.id_especialidad, { ...data, actualizado_en_original: base.actualizado_en })
      : crearEspecialidad(data));
    if (result?.data) navigate(`/administracion/especialidades/${result.data.id_especialidad}`, { replace: true, state: { specialtyNotice: base ? 'Cambios guardados. Se consultó nuevamente la especialidad.' : 'Especialidad registrada. Se consultó nuevamente el registro.' } });
    if (result?.failure?.stale || result?.failure?.uncertain) setBlocked(true);
  }
  return <form ref={form} className="catalog-panel catalog-editor" aria-label={initial ? 'Editar especialidad' : 'Registrar especialidad'} aria-busy={busy} onSubmit={submit} noValidate>
    <SpecialtyFailure failure={failure} remaining={remaining} />
    <fieldset className="catalog-editor-fields" disabled={frozen}><legend>Datos de la especialidad</legend>
      <FormField label="Nombre" required maxLength={120} value={draft.nombre} onChange={event => setDraft({ ...draft, nombre: event.target.value })} error={failure?.fields.nombre} autoComplete="off" />
      <div className="field"><label htmlFor={descriptionId}>Descripción (opcional)</label>
        <textarea id={descriptionId} rows={4} maxLength={2000} value={draft.descripcion} onChange={event => setDraft({ ...draft, descripcion: event.target.value })}
          aria-invalid={Boolean(failure?.fields.descripcion)} aria-describedby={`${descriptionId}-hint${failure?.fields.descripcion ? ` ${descriptionId}-error` : ''}`} />
        <p id={`${descriptionId}-hint`} className="field-hint">Hasta 2000 caracteres, en un solo párrafo sin saltos de línea.</p>
        {failure?.fields.descripcion && <p id={`${descriptionId}-error`} className="field-error">{failure.fields.descripcion}</p>}
      </div>
    </fieldset>
    {blocked && base && <SpecialtyVersionReview id={base.id_especialidad} onUse={current => {
      setBase(current); setDraft({ nombre: current.nombre, descripcion: current.descripcion || '' }); setBlocked(false); report(null);
    }} />}
    {blocked && !base && <p className="catalog-note">Conservamos los datos escritos. Revisa los registros antes de decidir si necesitas comenzar una nueva alta.</p>}
    <div className="catalog-actions"><Button type="submit" loading={busy} disabled={blocked || remaining > 0}>{busy ? 'Guardando…' : initial ? 'Guardar cambios' : 'Registrar especialidad'}</Button>
      <Link to={base ? `/administracion/especialidades/${base.id_especialidad}` : '/administracion/especialidades'} className="button button--secondary">{blocked && !base ? 'Consultar catálogo' : 'Volver sin guardar'}</Link></div>
  </form>;
}
