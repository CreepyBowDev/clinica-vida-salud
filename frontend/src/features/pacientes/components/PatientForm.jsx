import FormField from '../../../components/FormField';

export default function PatientForm({ draft, onChange, emergency, onEmergencyChange, busy, fields = {}, editing = false, documentRequired = false, contactOnly = false }) {
  const field = name => ({ name, value: draft[name], onChange: event => onChange(name, event.target.value), error: fields[name], autoComplete: 'off' });
  return <fieldset className="patient-form-fields" disabled={busy}><legend>{contactOnly ? 'Contacto y emergencia' : 'Datos de la ficha'}</legend>
    <div className="patient-form-grid">
      {!contactOnly && <><FormField label={documentRequired ? 'CI' : 'CI (opcional)'} required={documentRequired} maxLength={50} {...field('documento_identidad')} hint={editing ? 'Un CI informado no se puede retirar. Incorporarlo o corregirlo requiere comprobación presencial y motivo.' : 'Si no dispone de CI, revisa posibles fichas previas. No uses un documento ficticio.'} />
      <FormField label="Nombres" required maxLength={120} {...field('nombres')} />
      <FormField label="Apellidos" required maxLength={120} {...field('apellidos')} />
      <FormField label="Fecha de nacimiento" type="date" required {...field('fecha_nacimiento')} />
      <FormField label="Sexo (opcional)" maxLength={32} {...field('sexo')} /></>}
      <FormField label="Teléfono" type="tel" required maxLength={40} {...field('telefono')} />
      <FormField label="Correo de contacto (opcional)" type="email" maxLength={254} {...field('correo_contacto')} hint="No crea una cuenta ni cambia el correo de acceso." />
      <div className="field patient-form-wide"><label htmlFor="patient-address">Dirección (opcional)</label><textarea id="patient-address" name="direccion" value={draft.direccion} onChange={event => onChange('direccion', event.target.value)} maxLength={2000} rows={3} aria-invalid={Boolean(fields.direccion)} aria-describedby={fields.direccion ? 'patient-address-error' : undefined} />{fields.direccion && <p id="patient-address-error" className="field-error">{fields.direccion}</p>}</div>
    </div>
    <div className="patient-checkbox"><label><input type="checkbox" checked={emergency} onChange={event => onEmergencyChange(event.target.checked)} />Registrar contacto de emergencia</label></div>
    {emergency && <fieldset className="patient-emergency"><legend>Contacto de emergencia</legend>
      {fields.contacto_emergencia && <p className="field-error">{fields.contacto_emergencia}</p>}
      <div className="patient-form-grid"><FormField label="Nombre del contacto" required maxLength={240} {...field('contacto_emergencia.nombre')} />
        <FormField label="Parentesco" required maxLength={80} {...field('contacto_emergencia.parentesco')} />
        <FormField label="Teléfono de emergencia" type="tel" required maxLength={40} {...field('contacto_emergencia.telefono')} /></div>
    </fieldset>}
  </fieldset>;
}
