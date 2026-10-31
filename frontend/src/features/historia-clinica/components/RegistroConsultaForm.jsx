import { useEffect, useId, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import FormSection from '../../../components/FormSection';
import SelectField from '../../../components/SelectField';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import { ContenidoClinico, etiquetasConsulta, etiquetasAntecedentes } from './ContenidoClinico';
import { camposAlta, camposAntecedentesAlta, cambiosCorreccion } from '../services/consultaMedica.service';

function TextoClinico({ label, value, onChange, maxLength, error, required = false }) {
  const id = useId();
  return <div className="field"><label htmlFor={id}>{label}</label><textarea id={id} value={value} onChange={onChange} rows={4}
    maxLength={maxLength} required={required} aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : undefined} autoComplete="off" />
    {error && <p className="field-error" id={`${id}-error`}>{error}</p>}</div>;
}
const empty = () => Object.fromEntries(Object.keys(camposAlta).map(k => [k, '']));
export default function RegistroConsultaForm({ contexto, busy, failure, onGuardar, onDirtyChange, ejecutor }) {
  const actualizar = contexto.consulta !== null, vigente = contexto.consulta?.version_vigente;
  const [consulta, setConsulta] = useState(() => actualizar ? Object.fromEntries(Object.keys(camposAlta).map(k => [k, vigente[k] ?? ''])) : empty());
  const [modos, setModos] = useState({}), [valores, setValores] = useState({}), [modosConsulta, setModosConsulta] = useState({}), [motivo, setMotivo] = useState('');
  const [declaracion, setDeclaracion] = useState(false), [errors, setErrors] = useState({}), [revision, setRevision] = useState(null);
  const heading = useRef(null), declaration = useRef(null), summary = useRef(null);
  const dirty = (actualizar ? Object.keys(consulta).some(k => consulta[k] !== (vigente[k] ?? '')) : Object.values(consulta).some(Boolean))
    || Object.values(modos).some(m => m !== 'conservar') || Object.values(modosConsulta).some(m => m !== 'conservar') || declaracion || Boolean(motivo);
  useEffect(() => { onDirtyChange(dirty); }, [dirty, onDirtyChange]);
  useEffect(() => { if (revision) heading.current?.focus(); }, [revision]);
  useEffect(() => {
    if (failure) { setRevision(null); setDeclaracion(false); setErrors(failure.fields); summary.current?.focus(); }
  }, [failure]);
  const revisar = event => {
    event.preventDefault(); if (busy) return;
    const e = {};
    if (!['Control', 'Enfermedad'].includes(consulta.tipo_consulta)) e['consulta.tipo_consulta'] = 'Selecciona el tipo de consulta.';
    if (!consulta.motivo_consulta.trim()) e['consulta.motivo_consulta'] = 'Indica el motivo de consulta.';
    for (const [k, max] of Object.entries(camposAlta)) if ([...consulta[k]].length > max) e[`consulta.${k}`] = `Usa hasta ${max} caracteres.`;
    const antecedentes = {};
    for (const [k, max] of Object.entries(camposAntecedentesAlta)) {
      if (modos[k] === 'quitar') antecedentes[k] = null;
      if (modos[k] === 'establecer') {
        const value = valores[k] ?? contexto.antecedentes?.[k] ?? '';
        if (!value.trim() || [...value].length > max) e[`antecedentes.${k}`] = `Escribe el dato (hasta ${max} caracteres) o elige conservar o quitar.`;
        antecedentes[k] = value;
      }
    }
    const datos = actualizar ? {} : Object.fromEntries(Object.entries(consulta).map(([k, v]) => [k, v === '' && !['tipo_consulta', 'motivo_consulta'].includes(k) ? null : v]));
    if (actualizar) for (const k of Object.keys(camposAlta)) {
      if (['tipo_consulta', 'motivo_consulta'].includes(k)) { if (consulta[k] !== vigente[k]) datos[k] = consulta[k]; }
      else if (modosConsulta[k] === 'quitar') datos[k] = null;
      else if (modosConsulta[k] === 'establecer') {
        if (!consulta[k].trim()) e[`consulta.${k}`] = 'Escribe el dato o elige conservar o quitar.';
        datos[k] = consulta[k];
      }
    }
    const cambios = actualizar ? cambiosCorreccion(contexto, datos, antecedentes) : null;
    if (actualizar && (cambios.consulta || cambios.antecedentes) && (!motivo.trim() || [...motivo].length > 2000)) e.motivo_cambio = 'Indica un motivo de corrección de hasta 2000 caracteres.';
    if (!declaracion) e.atencion_real_verificada = 'Declara que la atención real fue iniciada o realizada.';
    setErrors(e);
    if (Object.keys(e).length) { summary.current?.focus(); return; }
    setRevision({ contexto, consulta: datos, antecedentes, declaracion: true, ...(actualizar ? { motivo_cambio: motivo } : {}) });
  };
  const volver = () => { setRevision(null); setDeclaracion(false); setErrors({}); requestAnimationFrame(() => declaration.current?.focus()); };
  const cambiosRevisados = revision && actualizar ? cambiosCorreccion(contexto, revision.consulta, revision.antecedentes) : null;
  const sinCambios = cambiosRevisados && !cambiosRevisados.consulta && !cambiosRevisados.antecedentes;
  return <div className="clinical-editor">
    <div ref={summary} tabIndex={-1}>{Object.keys(errors).length > 0 && <Alert><p>Revisa los campos indicados antes de continuar.</p><ul>{Object.entries(errors).map(([k, text]) => <li key={k}>{text}</li>)}</ul></Alert>}</div>
    {!revision ? <form aria-label={actualizar ? 'Corregir consulta' : 'Registrar consulta'} noValidate onSubmit={revisar}>
      <FormSection title="Datos de la consulta" disabled={busy}>
        <SelectField label="Tipo de consulta" required value={consulta.tipo_consulta} error={errors['consulta.tipo_consulta']} onChange={e => setConsulta({ ...consulta, tipo_consulta: e.target.value })}>
          <option value="">Selecciona un tipo</option><option value="Control">Control</option><option value="Enfermedad">Enfermedad</option>
        </SelectField>
        {Object.entries(camposAlta).filter(([k]) => k !== 'tipo_consulta').map(([k, max]) => actualizar && k !== 'motivo_consulta' ? <section className="clinical-background-edit" key={k} aria-label={etiquetasConsulta[k]}>
          <h3>{etiquetasConsulta[k]}</h3><p className="clinical-current">Dato vigente: {vigente[k] ?? 'No registrado'}</p>
          <SelectField label={`Acción para ${etiquetasConsulta[k].toLowerCase()}`} value={modosConsulta[k] ?? 'conservar'} onChange={e => setModosConsulta({ ...modosConsulta, [k]: e.target.value })}>
            <option value="conservar">Conservar dato vigente</option><option value="establecer">Registrar o modificar dato</option><option value="quitar">Quitar dato registrado</option>
          </SelectField>
          {modosConsulta[k] === 'establecer' && <TextoClinico label={`Nuevo dato: ${etiquetasConsulta[k].toLowerCase()}`} value={consulta[k]} maxLength={max}
            error={errors[`consulta.${k}`]} onChange={e => setConsulta({ ...consulta, [k]: e.target.value })} />}
        </section> : <TextoClinico key={k} label={etiquetasConsulta[k]} value={consulta[k]} maxLength={max} required={k === 'motivo_consulta'}
          error={errors[`consulta.${k}`]} onChange={e => setConsulta({ ...consulta, [k]: e.target.value })} />)}
        <p>{actualizar ? 'Conservar omite el campo. Quitar deja un dato opcional sin registrar, sin eliminar versiones anteriores.' : 'Los campos opcionales vacíos quedarán sin registrar. No significan ausencia de síntomas o enfermedades.'}</p>
      </FormSection>
      {actualizar && <FormSection title="Trazabilidad de la corrección" disabled={busy}>
        <TextoClinico label="Motivo de corrección" value={motivo} maxLength={2000} error={errors.motivo_cambio} onChange={e => setMotivo(e.target.value)} />
        <p>Obligatorio si cambias la consulta o los antecedentes. El servidor verifica originales y permisos incluso si no hay cambios.</p>
      </FormSection>}
      <FormSection title="Antecedentes del paciente" disabled={busy}>
        <p>Revisa los antecedentes vigentes antes de modificarlos. Conservar no cambia el dato; quitar lo deja sin registrar, sin eliminar versiones anteriores.</p>
        {Object.entries(camposAntecedentesAlta).map(([k, max]) => <section className="clinical-background-edit" key={k} aria-label={etiquetasAntecedentes[k]}>
          <h3>{etiquetasAntecedentes[k]}</h3><p className="clinical-current">Dato vigente: {contexto.antecedentes?.[k] ?? 'No registrado'}</p>
          <SelectField label={`Acción para ${etiquetasAntecedentes[k].toLowerCase()}`} value={modos[k] ?? 'conservar'} onChange={e => setModos({ ...modos, [k]: e.target.value })}>
            <option value="conservar">Conservar dato vigente</option><option value="establecer">Registrar o modificar dato</option><option value="quitar">Quitar dato registrado</option>
          </SelectField>
          {modos[k] === 'establecer' && <TextoClinico label={`Nuevo dato: ${etiquetasAntecedentes[k].toLowerCase()}`} value={valores[k] ?? contexto.antecedentes?.[k] ?? ''} maxLength={max}
            error={errors[`antecedentes.${k}`]} onChange={e => setValores({ ...valores, [k]: e.target.value })} />}
        </section>)}
      </FormSection>
      <fieldset disabled={busy} className="clinical-declaration"><legend>Atención real</legend>
        <label><input ref={declaration} type="checkbox" checked={declaracion} onChange={e => setDeclaracion(e.target.checked)}
          aria-invalid={Boolean(errors.atencion_real_verificada)} aria-describedby={`clinical-declaration-help${errors.atencion_real_verificada ? ' clinical-declaration-error' : ''}`} /> Declaro que la atención real de esta cita fue iniciada o realizada.</label>
        <p id="clinical-declaration-help">El tiempo transcurrido no demuestra atención. Guardar no marca la cita como Atendida.</p>
        {errors.atencion_real_verificada && <p id="clinical-declaration-error" className="field-error">{errors.atencion_real_verificada}</p>}
      </fieldset>
      <Button type="submit" disabled={busy}>{actualizar ? 'Revisar cambios' : 'Revisar registro'}</Button>
    </form> : <section aria-labelledby="clinical-registration-review">
      <h2 id="clinical-registration-review" ref={heading} tabIndex={-1}>{actualizar ? 'Revisar corrección de consulta' : 'Revisar registro de consulta'}</h2>
      <p>Usuario ejecutor: {ejecutor}. Médico responsable: {contexto.cita.medico.nombres} {contexto.cita.medico.apellidos}.</p>
      {actualizar ? <><h3>Cambios en la consulta</h3>
        {Object.entries(revision.consulta).some(([k,v]) => v !== vigente[k]) ? <dl className="clinical-data">{Object.entries(revision.consulta).filter(([k,v]) => v !== vigente[k]).map(([k,v]) => <div key={k}>
          <dt>{etiquetasConsulta[k]}</dt><dd>Vigente: {vigente[k] ?? 'No registrado'}{'\n'}Después de guardar: {v ?? 'Quitar dato; quedará sin registrar'}</dd>
        </div>)}</dl> : <p>No hay cambios efectivos en la consulta.</p>}
        {sinCambios && <p>No hay cambios efectivos. El servidor debe validar originales y permisos; si siguen vigentes, no se crearán versiones ni se registrará el motivo.</p>}
        <p className="clinical-current">Motivo de corrección: {sinCambios ? 'No aplica: no hay cambios efectivos.' : revision.motivo_cambio}</p>
      </> : <ContenidoClinico version={revision.consulta} tipo="consulta" />}
      <h3>Antecedentes aportados</h3>
      {Object.keys(revision.antecedentes).length ? <dl className="clinical-data">{Object.entries(revision.antecedentes).map(([k, v]) => <div key={k}>
        <dt>{etiquetasAntecedentes[k]}</dt><dd>Vigente: {contexto.antecedentes?.[k] ?? 'No registrado'}{'\n'}Después de guardar: {v ?? 'Quitar dato; quedará sin registrar'}</dd>
      </div>)}</dl> : <p>Se conservarán todos los antecedentes vigentes.</p>}
      <ConfirmationPanel title="Antes de guardar"><p>Has declarado atención real iniciada o realizada. Guardar no marca la cita como Atendida ni publica resultados de laboratorio.</p>
        <p>{actualizar ? 'Los cambios efectivos conservan versiones anteriores y el motivo. Un envío idéntico no crea una versión ni sustituye la comprobación de originales actuales.' : 'El paciente podrá consultar la información vigente guardada, aunque esté incompleta. Esta alta no admite una segunda consulta para la misma cita.'}</p>
        {actualizar && <p>El paciente verá solo la versión vigente, sin motivos internos ni versiones anteriores.</p>}</ConfirmationPanel>
      <ActionGroup><Button loading={busy} onClick={() => onGuardar(revision)}>{actualizar ? 'Guardar corrección' : 'Guardar consulta'}</Button><Button variant="quiet" disabled={busy} onClick={volver}>Volver al formulario</Button></ActionGroup>
    </section>}
  </div>;
}
