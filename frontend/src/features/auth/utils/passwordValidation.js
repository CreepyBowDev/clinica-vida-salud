export function evaluarRequisitosClave(password) {
  return [
    { id: 'length', label: 'Mínimo 12 caracteres', met: [...password].length >= 12 },
    { id: 'uppercase', label: 'Una mayúscula', met: /\p{Lu}/u.test(password) },
    { id: 'lowercase', label: 'Una minúscula', met: /\p{Ll}/u.test(password) },
    { id: 'number', label: 'Un número', met: /\p{Nd}/u.test(password) },
    { id: 'special', label: 'Un carácter especial', met: /[\p{P}\p{S}]/u.test(password) },
  ];
}

export function validarNuevaClave(password, confirmation) {
  const fields = {};
  const missing = evaluarRequisitosClave(password).filter(requirement => !requirement.met);
  if ([...password].length > 1024) fields.password = 'La contraseña admite hasta 1024 caracteres.';
  else if (missing.some(requirement => requirement.id === 'length')) fields.password = 'La contraseña debe tener al menos 12 caracteres.';
  else if (missing.length) fields.password = `Falta: ${missing.map(requirement => requirement.label.toLowerCase()).join(', ')}. Los espacios no cuentan como carácter especial.`;
  if (confirmation !== password) fields.password_confirmation = 'La confirmación de la contraseña no coincide.';
  return fields;
}
