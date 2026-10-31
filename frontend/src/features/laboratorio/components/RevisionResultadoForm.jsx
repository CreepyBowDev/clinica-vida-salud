import { useEffect, useId, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import SelectField from '../../../components/SelectField';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import { DECISIONES_RESULTADO } from '../services/revisionResultado.service';

export default function RevisionResultadoForm({ contexto, ejecutor, busy, failure, onGuardar, onDirtyChange, onCancel }) {
  const [decision, setDecision] = useState(''), [motivo, setMotivo] = useState(''), [declaracion, setDeclaracion] = useState(false), [errors, setErrors] = useState({}), [revision, setRevision] = useState(null);
  const reason = useId(), declarationId = useId(), heading = useRef(null), summary = useRef(null), formHeading = useRef(null);
  useEffect(() => { onDirtyChange(Boolean(decision || motivo || declaracion)); }, [decision, motivo, declaracion, onDirtyChange]);
  useEffect(() => { if (revision) heading.current?.focus(); else if (failure) summary.current?.focus(); else formHeading.current?.focus(); }, [revision, failure]);
  useEffect(() => { if (failure) { setRevision(null); setDeclaracion(false); setErrors(failure.fields); summary.current?.focus(); } }, [failure]);
  function revisar(e) {
    e.preventDefault(); if (busy) return;
    const errors = {};
    if (!Object.hasOwn(DECISIONES_RESULTADO, decision)) errors.decision = 'Selecciona Autorizar publicación o Devolver para corrección.';
    if (decision === 'Devolver' && (!motivo.trim() || [...motivo.trim()].length > 2000)) errors.motivo = 'Indica el motivo de devolución (hasta 2000 caracteres).';
    if (!declaracion) errors.correspondencia_verificada = 'Comprueba legibilidad, paciente y estudio antes de continuar.';
    setErrors(errors); if (Object.keys(errors).length) { summary.current?.focus(); return; }
    setRevision({ contexto, decision, declaracion: true, ...(decision === 'Devolver' ? { motivo: motivo.trim() } : {}) });
  }
  return <section className="lab-review-form" aria-label="Decisión sobre el resultado">
    <div ref={summary} tabIndex={-1}>{Object.keys(errors).length > 0 && <Alert><p>Revisa los campos indicados.</p><ul>{Object.entries(errors).map(([k, v]) => <li key={k}>{v}</li>)}</ul></Alert>}</div>
    {!revision ? <form aria-label="Revisar resultado de laboratorio" noValidate onSubmit={revisar}><fieldset disabled={busy} className="lab-form-fields"><legend ref={formHeading} tabIndex={-1}>Revisión del resultado vigente</legend>
      <p>Examina el documento mediante su descarga protegida y comprueba que corresponde al paciente y estudio indicados. No se abre un visor dentro del portal.</p>
      <SelectField label="Decisión de revisión" required value={decision} error={errors.decision} onChange={e => { setDecision(e.target.value); setDeclaracion(false); if (e.target.value !== 'Devolver') setMotivo(''); }}>
        <option value="">Selecciona una decisión</option>{Object.entries(DECISIONES_RESULTADO).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
      </SelectField>
      {decision === 'Devolver' && <div className="field"><label htmlFor={reason}>Motivo de devolución</label><textarea id={reason} rows={4} autoComplete="off" required value={motivo} onChange={e => setMotivo(e.target.value)}
        aria-invalid={Boolean(errors.motivo)} aria-describedby={`${reason}-help${errors.motivo ? ` ${reason}-error` : ''}`} /><p id={`${reason}-help`} className="field-hint">Hasta 2000 caracteres. Indica qué debe corregirse, sin alterar el documento externo.</p>
        {errors.motivo && <p id={`${reason}-error`} className="field-error">{errors.motivo}</p>}</div>}
      <div className="lab-declaration"><label><input type="checkbox" checked={declaracion} onChange={e => setDeclaracion(e.target.checked)} aria-invalid={Boolean(errors.correspondencia_verificada)} aria-describedby={errors.correspondencia_verificada ? `${declarationId}-error` : undefined} /><span>He comprobado la legibilidad y correspondencia del documento con el paciente y el estudio.</span></label>
        {errors.correspondencia_verificada && <p id={`${declarationId}-error`} className="field-error">{errors.correspondencia_verificada}</p>}</div>
    </fieldset><ActionGroup><Button type="submit" disabled={busy}>Revisar decisión</Button><Button variant="quiet" disabled={busy} onClick={onCancel}>Cancelar revisión</Button></ActionGroup></form>
      : <section aria-labelledby="lab-review-confirmation"><h2 id="lab-review-confirmation" tabIndex={-1} ref={heading}>{DECISIONES_RESULTADO[revision.decision]}</h2>
        <dl className="lab-data"><div><dt>Paciente</dt><dd>{contexto.paciente.nombres} {contexto.paciente.apellidos}<span className="lab-note">{contexto.paciente.codigo_paciente}</span></dd></div>
          <div><dt>Médico responsable</dt><dd>{contexto.medico.nombres} {contexto.medico.apellidos}</dd></div><div><dt>Versión revisada</dt><dd>{contexto.documento.numero_version}</dd></div><div><dt>Usuario ejecutor</dt><dd>{ejecutor}</dd></div>
          <div className="lab-wide"><dt>Estudio</dt><dd>{contexto.estudio_solicitado}</dd></div>{revision.decision === 'Devolver' && <div className="lab-wide"><dt>Motivo de devolución</dt><dd className="lab-text">{revision.motivo}</dd></div>}</dl>
        <ConfirmationPanel title="Antes de confirmar"><p>{revision.decision === 'Autorizar' ? 'Autorizar publica directamente esta versión vigente como Disponible para el Paciente titular. No es una carga ni una devolución.' : 'El documento permanecerá En revisión, sin acceso para el Paciente. Deberá corregirse antes de una nueva revisión.'}</p><p>Se conservarán el Médico responsable y tu identidad como usuario ejecutor. Esta decisión no cambia el estado de la cita.</p></ConfirmationPanel>
        <ActionGroup><Button disabled={busy} onClick={() => onGuardar(revision)}>{busy ? 'Guardando revisión…' : DECISIONES_RESULTADO[revision.decision]}</Button>
          <Button variant="secondary" disabled={busy} onClick={() => { setRevision(null); setDeclaracion(false); }}>Volver a editar la decisión</Button></ActionGroup>
      </section>}
  </section>;
}
