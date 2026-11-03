import { useEffect, useId, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import { validarArchivoLaboratorio, esDestinoDocumento, ACCIONES_DOCUMENTO } from '../services/documentoLaboratorio.service';
import SeleccionarDestinoDocumento from './SeleccionarDestinoDocumento';

function Identidad({ solicitud, label }) {
  return <section className="lab-association" aria-label={label}><h3>{label}</h3><p><strong>{solicitud.paciente.nombres} {solicitud.paciente.apellidos}</strong> — {solicitud.paciente.codigo_paciente}</p>
    <p>Estudio: {solicitud.estudio_solicitado}</p><p>Médico responsable: {solicitud.medico.nombres} {solicitud.medico.apellidos}</p><p>{solicitud.documento ? `Versión vigente ${solicitud.documento.numero_version}` : 'Sin documento vigente'}</p></section>;
}
export default function DocumentoLaboratorioForm({ contexto, tipo, busy, failure, ejecutor, onGuardar, onDirtyChange, onCancel }) {
  const [archivo, setArchivo] = useState(null), [motivo, setMotivo] = useState(''), [asociacion, setAsociacion] = useState(false), [asociacionDestino, setAsociacionDestino] = useState(false);
  const [destino, setDestino] = useState(null), [buscando, setBuscando] = useState(false), [revision, setRevision] = useState(null), [errors, setErrors] = useState({}), [fileKey, setFileKey] = useState(0);
  const field = useId(), reason = useId(), originCheck = useId(), targetCheck = useId(), heading = useRef(null), summary = useRef(null), formHeading = useRef(null);
  const requiereMotivo = tipo !== 'cargar' || contexto.edicion.originales.ultima_version > 0;
  useEffect(() => { onDirtyChange(true); }, [onDirtyChange]);
  useEffect(() => { if (revision) heading.current?.focus(); }, [revision]);
  useEffect(() => { if (!buscando && destino) formHeading.current?.focus(); }, [buscando, destino]);
  useEffect(() => {
    if (failure) { setArchivo(null); setFileKey(v => v + 1); setRevision(null); setAsociacion(false); setAsociacionDestino(false); setErrors(failure.fields); summary.current?.focus(); }
  }, [failure]);
  function revisar(e) {
    e.preventDefault(); if (busy) return;
    const errors = {};
    if (tipo !== 'reasociar') { const error = validarArchivoLaboratorio(archivo); if (error) errors.archivo = error; }
    if (requiereMotivo && (!motivo.trim() || [...motivo.trim()].length > 2000)) errors.motivo_cambio = 'Indica un motivo de hasta 2000 caracteres.';
    if (!asociacion) errors.asociacion_verificada = 'Comprueba explícitamente paciente y estudio de origen.';
    if (tipo === 'reasociar') {
      if (!esDestinoDocumento(destino, contexto)) errors.destino = 'Selecciona y comprueba una solicitud de destino válida.';
      if (!asociacionDestino) errors.asociacion_destino_verificada = 'Comprueba explícitamente paciente y estudio de destino.';
    }
    setErrors(errors); if (Object.keys(errors).length) { summary.current?.focus(); return; }
    setRevision({ contexto, tipo, ...(tipo !== 'reasociar' ? { archivo } : { destino, asociacionDestino: true }), motivo: motivo.trim(), asociacion: true });
  }
  if (buscando) return <SeleccionarDestinoDocumento origen={contexto} onCancel={() => setBuscando(false)} onSeleccionar={d => { setDestino(d); setAsociacion(false); setAsociacionDestino(false); setBuscando(false); }} />;
  return <section className="lab-document-form" aria-label={ACCIONES_DOCUMENTO[tipo]}>
    <div ref={summary} tabIndex={-1}>{Object.keys(errors).length > 0 && <Alert><p>Revisa los datos de la operación.</p><ul>{Object.entries(errors).map(([k, v]) => <li key={k}>{v}</li>)}</ul></Alert>}</div>
    {!revision ? <form aria-label={ACCIONES_DOCUMENTO[tipo]} noValidate onSubmit={revisar}><fieldset disabled={busy} className="lab-form-fields"><legend tabIndex={-1} ref={formHeading}>{ACCIONES_DOCUMENTO[tipo]}</legend>
      {tipo !== 'reasociar' ? <div className="field"><label htmlFor={field}>Documento externo</label><input key={fileKey} id={field} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required
        aria-invalid={Boolean(errors.archivo)} aria-describedby={`${field}-help${errors.archivo ? ` ${field}-error` : ''}`} onChange={e => { setArchivo(e.target.files?.[0] ?? null); setAsociacion(false); }} />
        <p id={`${field}-help`} className="field-hint">Un PDF, JPEG o PNG de hasta 10 MiB. El servidor comprueba el contenido; el formato no garantiza legibilidad ni ausencia de malware.</p>
        {errors.archivo && <p id={`${field}-error`} className="field-error">{errors.archivo}</p>}</div>
        : <><div className="lab-associations"><Identidad solicitud={contexto} label="Origen del documento" />{destino && <Identidad solicitud={destino} label="Destino del documento" />}</div>
          <Button variant="secondary" onClick={() => { setBuscando(true); setAsociacion(false); setAsociacionDestino(false); }}>Seleccionar paciente y solicitud de destino</Button>
          {errors.destino && <p className="field-error">{errors.destino}</p>}</>}
      {requiereMotivo && <div className="field"><label htmlFor={reason}>Motivo de corrección</label><textarea id={reason} rows={3} value={motivo} autoComplete="off" required onChange={e => setMotivo(e.target.value)}
        aria-invalid={Boolean(errors.motivo_cambio)} aria-describedby={errors.motivo_cambio ? `${reason}-error` : undefined} />
        <p className="field-hint">Hasta 2000 caracteres. No alteres la interpretación médica del documento.</p>{errors.motivo_cambio && <p id={`${reason}-error`} className="field-error">{errors.motivo_cambio}</p>}</div>}
      <div className="lab-declaration"><label><input id={originCheck} type="checkbox" checked={asociacion} onChange={e => setAsociacion(e.target.checked)} aria-invalid={Boolean(errors.asociacion_verificada)} aria-describedby={errors.asociacion_verificada ? `${originCheck}-error` : undefined} /> He comprobado el paciente y el estudio de {tipo === 'reasociar' ? 'origen' : 'esta solicitud'}.</label>
        {errors.asociacion_verificada && <p id={`${originCheck}-error`} className="field-error">{errors.asociacion_verificada}</p>}</div>
      {tipo === 'reasociar' && <div className="lab-declaration"><label><input id={targetCheck} type="checkbox" checked={asociacionDestino} onChange={e => setAsociacionDestino(e.target.checked)} aria-invalid={Boolean(errors.asociacion_destino_verificada)} aria-describedby={errors.asociacion_destino_verificada ? `${targetCheck}-error` : undefined} /> He comprobado el paciente y el estudio de destino.</label>
        {errors.asociacion_destino_verificada && <p id={`${targetCheck}-error`} className="field-error">{errors.asociacion_destino_verificada}</p>}</div>}
    </fieldset><ActionGroup><Button type="submit" disabled={busy}>Revisar operación documental</Button><Button variant="quiet" disabled={busy} onClick={onCancel}>Cancelar operación documental</Button></ActionGroup></form>
      : <section aria-labelledby="lab-document-review"><h2 id="lab-document-review" tabIndex={-1} ref={heading}>Revisar: {ACCIONES_DOCUMENTO[tipo].toLowerCase()}</h2>
        <div className="lab-associations"><Identidad solicitud={contexto} label="Origen del documento" />{destino && <Identidad solicitud={destino} label="Destino del documento" />}</div>
        {archivo && <p className="lab-text">Archivo seleccionado: {archivo.name} ({Math.ceil(archivo.size / 1024)} KB)</p>}{requiereMotivo && <p className="lab-text">Motivo: {revision.motivo}</p>}
        <p>Usuario ejecutor: {ejecutor}.</p><ConfirmationPanel title="Antes de guardar"><p>{tipo === 'reasociar' ? 'El origen conservará su historial y quedará sin documento vigente. El destino recibirá el documento y requerirá una nueva revisión.' : 'Se conservarán las versiones anteriores. El documento quedará En revisión.'}</p><p>Esta operación no publica resultados ni cambia el estado de la cita.</p></ConfirmationPanel>
        <ActionGroup><Button disabled={busy} onClick={() => onGuardar(revision)}>{busy ? 'Guardando documento…' : ACCIONES_DOCUMENTO[tipo]}</Button><Button variant="secondary" disabled={busy} onClick={() => { setRevision(null); setAsociacion(false); setAsociacionDestino(false); setArchivo(null); setFileKey(v => v + 1); requestAnimationFrame(() => formHeading.current?.focus()); }}>Volver a editar y verificar</Button></ActionGroup>
      </section>}
  </section>;
}
