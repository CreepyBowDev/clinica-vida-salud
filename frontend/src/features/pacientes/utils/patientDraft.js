const scalarFields = ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_contacto', 'direccion'];
const identityFields = ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo'];
export function borradorPaciente(patient) {
  const draft = Object.fromEntries(scalarFields.map(name => [name, patient[name] ?? '']));
  for (const name of ['nombre', 'parentesco', 'telefono']) draft[`contacto_emergencia.${name}`] = patient.contacto_emergencia?.[name] ?? '';
  return draft;
}

export function cambiosPaciente(patient, draft, emergency) {
  const changes = {};
  for (const name of scalarFields) {
    const optional = ['documento_identidad', 'sexo', 'correo_contacto', 'direccion'].includes(name);
    const value = draft[name].trim() || (optional ? null : '');
    let original = patient[name] ?? (optional ? null : '');
    if (name === 'documento_identidad' && original !== null) original = original.trim();
    if (value !== original) changes[name] = value;
  }
  const contact = emergency ? Object.fromEntries(['nombre', 'parentesco', 'telefono'].map(name => [name, draft[`contacto_emergencia.${name}`].trim()])) : null;
  const original = patient.contacto_emergencia;
  if (contact === null ? original != null : !original || Object.keys(contact).some(name => contact[name] !== original[name])) changes.contacto_emergencia = contact;
  return { changes, identityChanged: identityFields.some(name => Object.hasOwn(changes, name)) };
}
