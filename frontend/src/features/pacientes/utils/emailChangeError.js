import { describirError } from '../../../utils/httpError';

export function describirErrorCambioCorreo(error, action) {
  const failure = describirError(error, { operation: 'emailChange', allowedFields: ['correo_acceso', 'current_password', 'codigo'] });
  const code = error.response?.data?.error?.code;
  if (code === 'CAMBIO_CORREO_NO_DISPONIBLE') failure.message = 'No se puede utilizar ese correo para este cambio. Revisa los datos o utiliza otro.';
  if (code === 'CAMBIO_CORREO_INVALIDO') failure.message = 'El código o la solicitud no es válido, venció o dejó de estar disponible. Usa el código más reciente; si el reenvío no está permitido, inicia una nueva solicitud con tu contraseña.';
  if (code === 'AUTH_CLAVE_ACTUAL_INVALIDA') { failure.message = 'La contraseña actual no es correcta.'; failure.fields.current_password = failure.message; }
  if (action !== 'check' && (!failure.status || failure.status >= 500)) {
    failure.uncertain = true;
    failure.message = 'No pudimos confirmar el resultado. La operación podría haberse completado. Comprueba tu sesión antes de decidir otra solicitud; no se reenviará automáticamente. Si la sesión terminó, vuelve a iniciar sesión con el correo vigente.';
  }
  if (action === 'check' && !failure.status) failure.message = 'No pudimos comprobar tu perfil. Revisa tu conexión y vuelve a consultarlo; los envíos siguen bloqueados.';
  return failure;
}
