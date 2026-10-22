import { useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import { crearHorario, actualizarHorario, cambiarEstadoHorario, consultarImpactoHorario } from '../services/horario.service';
import { dias, diaSemana, horaLocal } from '../utils/horarioFormat';
import { useHorarioSubmission } from '../hooks/useHorarioSubmission';
import HorarioImpacto from './HorarioImpacto';

export default function HorarioEditor({ id, version, initial, accion, onClose, onSaved }) {
  const [draft, setDraft] = useState({ dia_semana: String(initial?.dia_semana || 1), hora_inicio: initial?.hora_inicio || '', hora_fin: initial?.hora_fin || '' });
  const [impacto, setImpacto] = useState(null), [confirm, setConfirm] = useState(false);
  const form = useRef(null), review = useRef(null);
  const { busy, failure, blocked, remaining, execute, cancel, report } = useHorarioSubmission(form);
  const estado = accion === 'ESTADO';
  const title = estado ? (initial.activo ? 'Desactivar horario' : 'Reactivar horario') : initial ? 'Editar horario' : 'Registrar horario';
  function change(key, value) { cancel(); setDraft(previous => ({ ...previous, [key]: value })); setImpacto(null); setConfirm(false); report(null); }
  function datos() {
    if (estado) return { activo: !initial.activo };
    const errors = {};
    if (!/^[1-7]$/.test(draft.dia_semana)) errors.dia_semana = 'Selecciona un día válido.';
    for (const key of ['hora_inicio', 'hora_fin']) if (horaLocal(draft[key]) === 'No disponible') errors[key] = 'Introduce una hora válida con segundos: HH:mm:ss.';
    if (!Object.keys(errors).length && draft.hora_fin <= draft.hora_inicio) errors.hora_fin = 'La hora final debe ser posterior a la inicial, sin cruzar medianoche.';
    if (Object.keys(errors).length) { report({ message: 'Revisa los campos indicados.', fields: errors }); return null; }
    const values = { dia_semana: Number(draft.dia_semana), hora_inicio: draft.hora_inicio, hora_fin: draft.hora_fin };
    const payload = initial ? Object.fromEntries(Object.entries(values).filter(([key, value]) => initial[key] !== value)) : values;
    if (!Object.keys(payload).length) { report({ message: 'No hay cambios para guardar.', fields: {} }); return null; }
    return payload;
  }
  async function preview(page = 1) {
    if (busy || blocked || remaining) return;
    const payload = datos(); if (!payload) return;
    setImpacto(null); setConfirm(false);
    const result = await execute('preview', signal => consultarImpactoHorario(id, { ...payload, accion, ...(initial ? { id_horario: initial.id_horario } : {}), actualizado_en_original: version, page }, signal));
    if (result?.data) { setImpacto(result.data); requestAnimationFrame(() => review.current?.focus()); }
  }
  async function save(event) {
    event.preventDefault();
    if (busy || blocked || remaining || !confirm || !impacto?.data.puede_confirmar) return;
    const payload = datos(); if (!payload) return;
    setConfirm(false); setImpacto(null);
    const data = { ...payload, actualizado_en_original: version };
    const result = await execute('write', () => estado ? cambiarEstadoHorario(id, initial.id_horario, data) : initial ? actualizarHorario(id, initial.id_horario, data) : crearHorario(id, data));
    if (result?.data) onSaved();
  }
  return <form ref={form} className="catalog-panel catalog-editor horario-editor" aria-label={title} aria-busy={Boolean(busy)} onSubmit={save} noValidate>
    <h2>{title}</h2>
    {failure && <Alert tabIndex="-1"><p>{failure.message}</p>{remaining > 0 && <p>Espera {remaining} segundos antes de volver a intentarlo.</p>}</Alert>}
    {estado ? <p>{diaSemana(initial.dia_semana)}, de {horaLocal(initial.hora_inicio)} a {horaLocal(initial.hora_fin)}. {initial.activo ? 'Desactivar libera la franja administrativa, pero no cancela ni mueve citas existentes.' : 'Reactivar exige compatibilidad con la sala, duración y ocupación actuales.'}</p>
      : <fieldset className="catalog-editor-fields" disabled={busy === 'write' || blocked}><legend>Intervalo semanal de atención</legend>
        <SelectField label="Día de atención" value={draft.dia_semana} error={failure?.fields?.dia_semana} onChange={event => change('dia_semana', event.target.value)}>{dias.map((dia, index) => <option value={index + 1} key={dia}>{dia}</option>)}</SelectField>
        <div className="horario-hours">{[['hora_inicio', 'Hora inicial'], ['hora_fin', 'Hora final']].map(([key, label]) => <FormField key={key} label={label} type="text" inputMode="text" placeholder="08:00:00" maxLength={8} autoComplete="off" value={draft[key]} onChange={event => change(key, event.target.value)} error={failure?.fields?.[key]} hint="Hora local con segundos, HH:mm:ss." />)}</div>
      </fieldset>}
    <p className="catalog-note">La actividad del Médico no cambia con esta operación. El servidor comprueba horarios compartidos y cobertura completa de citas vigentes; no se reprograman ni cancelan automáticamente.</p>
    {blocked && <p>El borrador sigue visible. Para continuar, descártalo explícitamente y consulta el listado actual.</p>}
    <div className="catalog-actions"><Button variant="secondary" disabled={Boolean(busy) || blocked || remaining > 0} onClick={() => preview()}>Consultar impacto del horario</Button>
      <Button variant="quiet" disabled={Boolean(busy)} onClick={onClose}>{blocked ? 'Descartar borrador y consultar horarios actuales' : 'Volver al listado sin guardar'}</Button></div>
    {busy && <p role="status">{busy === 'write' ? 'Guardando horario…' : 'Consultando impacto…'}</p>}
    <div ref={review} tabIndex="-1">{impacto && <HorarioImpacto impacto={impacto} onPage={preview} disabled={Boolean(busy) || remaining > 0} />}</div>
    {impacto?.data.puede_confirmar && <section className="catalog-confirm" aria-label="Confirmación de horario"><h2>Confirmar propuesta de horario</h2>
      <p>El diagnóstico comprende la propuesta completa, no solo esta página. No reserva la franja: el servidor volverá a validar al guardar.</p>
      {confirm && <p role="status">Revisa el cambio: {title.toLowerCase()}. Las citas existentes conservarán sus datos.</p>}
      <div className="catalog-actions">{confirm ? <><Button type="submit" loading={busy === 'write'} disabled={Boolean(busy) || blocked || remaining > 0}>Guardar horario</Button><Button variant="quiet" disabled={Boolean(busy)} onClick={() => setConfirm(false)}>Volver a revisar</Button></>
        : <Button disabled={Boolean(busy) || blocked || remaining > 0} onClick={() => setConfirm(true)}>Revisar cambio de horario</Button>}</div>
    </section>}
  </form>;
}
