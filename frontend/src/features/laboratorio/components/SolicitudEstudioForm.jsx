import { useEffect, useId, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import FormSection from '../../../components/FormSection';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';

export default function SolicitudEstudioForm({ contexto, busy, failure, ejecutor, onGuardar, onDirtyChange }) {
  const [estudio, setEstudio] = useState(''), [declaracion, setDeclaracion] = useState(false), [errors, setErrors] = useState({}), [revision, setRevision] = useState(null);
  const field = useId(), declarationId = useId(), heading = useRef(null), summary = useRef(null), declaration = useRef(null);
  useEffect(() => { onDirtyChange(Boolean(estudio || declaracion)); }, [estudio, declaracion, onDirtyChange]);
  useEffect(() => { if (revision) heading.current?.focus(); }, [revision]);
  useEffect(() => {
    if (failure) { setRevision(null); setDeclaracion(false); setErrors(failure.fields); summary.current?.focus(); }
  }, [failure]);
  function revisar(event) {
    event.preventDefault(); if (busy) return;
    const e = {};
    if (!estudio.trim() || [...estudio.trim()].length > 255) e.estudio_solicitado = 'Indica el estudio solicitado (hasta 255 caracteres).';
    if (!declaracion) e.atencion_real_verificada = 'Declara que la atención real fue iniciada o realizada.';
    setErrors(e);
    if (Object.keys(e).length) { summary.current?.focus(); return; }
    // La revisión conserva el contexto y los originales leídos, no los reemplaza silenciosamente al guardar.
    setRevision({ contexto, estudio: estudio.trim(), declaracion: true });
  }
  return <div className="lab-request-form">
    <div ref={summary} tabIndex={-1}>{Object.keys(errors).length > 0 && <Alert><p>Revisa los campos indicados antes de continuar.</p><ul>{Object.entries(errors).map(([k, v]) => <li key={k}>{v}</li>)}</ul></Alert>}</div>
    {!revision ? <form aria-label="Solicitar estudio de laboratorio" noValidate onSubmit={revisar}>
      <FormSection title="Estudio requerido" disabled={busy}>
        <div className="field"><label htmlFor={field}>Estudio solicitado</label><textarea id={field} rows={3} autoComplete="off" required value={estudio}
          aria-invalid={Boolean(errors.estudio_solicitado)} aria-describedby={`${field}-help${errors.estudio_solicitado ? ` ${field}-error` : ''}`} onChange={e => setEstudio(e.target.value)} />
          <p id={`${field}-help`} className="field-hint">Escribe el nombre o la descripción del estudio, hasta 255 caracteres. {[...estudio].length}/255</p>
          {errors.estudio_solicitado && <p id={`${field}-error`} className="field-error">{errors.estudio_solicitado}</p>}
        </div>
        <fieldset className="lab-declaration"><legend>Atención real</legend>
          <label><input ref={declaration} type="checkbox" checked={declaracion} onChange={e => setDeclaracion(e.target.checked)}
            aria-invalid={Boolean(errors.atencion_real_verificada)} aria-describedby={`${declarationId}-help${errors.atencion_real_verificada ? ` ${declarationId}-error` : ''}`} /> Declaro que la atención real de esta cita fue iniciada o realizada.</label>
          <p id={`${declarationId}-help`}>Solicitar un estudio no registra otra consulta ni marca la cita como Atendida.</p>
          {errors.atencion_real_verificada && <p id={`${declarationId}-error`} className="field-error">{errors.atencion_real_verificada}</p>}
        </fieldset>
      </FormSection><Button type="submit" disabled={busy}>Revisar solicitud</Button>
    </form> : <section aria-labelledby="lab-request-review"><h2 id="lab-request-review" ref={heading} tabIndex={-1}>Revisar solicitud de estudio</h2>
      <dl className="lab-data"><div><dt>Paciente</dt><dd>{contexto.paciente.nombres} {contexto.paciente.apellidos}<span className="lab-note">{contexto.paciente.codigo_paciente}</span></dd></div>
        <div><dt>Médico responsable</dt><dd>{contexto.medico.nombres} {contexto.medico.apellidos}</dd></div>
        <div><dt>Consulta</dt><dd>{contexto.codigo_consulta}</dd></div><div><dt>Usuario ejecutor</dt><dd>{ejecutor}</dd></div>
        <div className="lab-wide"><dt>Estudio solicitado</dt><dd className="lab-text">{revision.estudio}</dd></div></dl>
      <ConfirmationPanel title="Antes de solicitar"><p>Has declarado que la atención real fue iniciada o realizada. El estudio quedará Pendiente; su resultado se incorporará por separado.</p>
        <p>Una consulta puede tener varios estudios, incluso del mismo nombre. Comprueba que esta solicitud es necesaria.</p>
      </ConfirmationPanel>
      <ActionGroup><Button disabled={busy} onClick={() => onGuardar(revision)}>{busy ? 'Solicitando…' : 'Solicitar estudio'}</Button>
        <Button variant="secondary" disabled={busy} onClick={() => { setRevision(null); setDeclaracion(false); requestAnimationFrame(() => declaration.current?.focus()); }}>Volver a editar</Button></ActionGroup>
    </section>}
  </div>;
}
