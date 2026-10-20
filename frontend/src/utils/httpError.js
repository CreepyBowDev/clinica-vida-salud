const messages = {
  401: 'No fue posible iniciar sesión con esos datos. Revisa tu correo y contraseña.',
  403: 'El acceso no está permitido. Comprueba que estás usando el portal de la clínica.',
  409: 'Ya existe una sesión. Comprueba tu sesión actual antes de volver a ingresar.',
  419: 'La protección de la sesión ha caducado. Vuelve a intentarlo para renovarla.',
  422: 'Revisa los campos indicados para continuar.',
  429: 'Hay demasiados intentos. Espera antes de volver a ingresar.',
};

export function describirError(error, { allowedFields = ['correo_acceso', 'password'], operation = 'login' } = {}) {
  const status = error.response?.status;
  const details = status === 422 ? error.response?.data?.error?.details : null;
  const fields = {};
  for (const key of allowedFields) {
    if (Array.isArray(details?.[key]) && typeof details[key][0] === 'string') {
      fields[key] = details[key][0];
    }
  }
  const seconds = Number(error.response?.headers?.['retry-after']);
  const retryAfter = status === 429 && Number.isFinite(seconds) && seconds > 0
    ? Math.ceil(seconds) : 0;

  const code = error.response?.data?.error?.code;
  const passwordMessage = status === 422 && code === 'AUTH_CLAVE_ACTUAL_INVALIDA'
    ? 'La contraseña actual no es correcta.'
    : status === 422 && code === 'RECUPERACION_INVALIDA'
      ? 'El enlace no es válido o ya no está disponible. Solicita uno nuevo.'
      : status === 422 && code === 'INVITACION_INVALIDA'
        ? 'La invitación no es válida o ya no está disponible. Solicita otra a Administración.' : null;
  const message = operation === 'login' ? messages[status] : {
    401: 'Tu sesión ha finalizado. Inicia sesión para continuar.',
    403: 'No tienes permiso para realizar esta operación.',
    409: code === 'AUTH_SESION_EXISTENTE' ? 'Hay una sesión activa en este navegador. Ciérrala antes de aceptar la invitación.' : 'No se pudo completar la operación en el estado actual.',
    419: 'La protección de la sesión ha caducado. Vuelve a enviar el formulario para renovarla.',
    422: passwordMessage || 'Revisa los campos indicados para continuar.',
    429: 'Hay demasiadas solicitudes. Espera antes de volver a intentarlo.',
  }[status];

  return {
    status,
    fields,
    retryAfter,
    invalidLink: status === 422 && code === 'RECUPERACION_INVALIDA',
    // No se muestra contenido técnico ni mensajes arbitrarios de proxies/servidores.
    message: !error.response
      ? operation === 'login'
        ? 'No pudimos confirmar el resultado. Comprueba tu conexión y consulta tu sesión antes de reenviar.'
        : 'No pudimos confirmar el resultado. La operación podría haberse completado. Comprueba tu conexión antes de volver a intentarlo.'
      : (message || 'No se pudo completar la operación. Inténtalo más tarde.'),
  };
}
