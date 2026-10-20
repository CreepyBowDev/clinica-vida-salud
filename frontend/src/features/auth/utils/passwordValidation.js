export function validarNuevaClave(password, confirmation) {
  const fields = {};
  if ([...password].length < 12) fields.password = 'La contraseña debe tener al menos 12 caracteres.';
  if (confirmation !== password) fields.password_confirmation = 'La confirmación de la contraseña no coincide.';
  return fields;
}
