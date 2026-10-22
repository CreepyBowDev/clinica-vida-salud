const fechaSede = new Intl.DateTimeFormat('es-BO', {
  timeZone: 'America/La_Paz', day: '2-digit', month: '2-digit', year: 'numeric',
  hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
});
const actores = { USUARIO: 'Usuario', SISTEMA: 'Sistema', ANONIMO: 'Anónimo' };
const resultados = { EXITO: 'Éxito', FALLO: 'Fallo', DENEGADO: 'Denegado', ERROR: 'Error' };
const causas = {
  CLAVE_ACTUAL_INVALIDA: 'Contraseña actual incorrecta', ENVIO_SIMULADO: 'Envío simulado',
  ENVIO_NO_VIGENTE: 'Envío sin vigencia', ENVIO_NO_CONFIRMADO: 'Envío no confirmado', CODIGO_INVALIDO: 'Código no válido',
  REGISTRO_NO_APLICABLE: 'Registro no aplicable', ACTIVACION_CODIGO_INVALIDO: 'Código de activación no válido',
  REGISTRO_ENVIO_LIMITADO: 'Límite de envíos de registro', REGISTRO_CODIGO_INVALIDO: 'Código de registro no válido',
  INVITACION_INVALIDA: 'Invitación no válida', RECUPERACION_NO_APLICABLE: 'Recuperación no aplicable',
  RECUPERACION_INVALIDA: 'Recuperación no válida', AUTH_NO_AUTENTICADO: 'Sesión no autenticada', ACCESO_DENEGADO: 'Acceso denegado',
  PERSONAL_ROL_INVALIDO: 'Rol de personal no válido', PERSONAL_VINCULO_INVALIDO: 'Vínculo de personal no válido',
  PERSONAL_INVITACION_NO_PERMITIDA: 'Invitación de personal no permitida', CUENTA_TRANSICION_INVALIDA: 'Cambio de estado de cuenta no permitido',
  CUENTA_ROL_NO_PERMITIDO: 'Cambio de rol de cuenta no permitido',
};

export function formatearFechaEvento(valor) {
  if (typeof valor !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(valor)) return 'No disponible';
  const fecha = new Date(valor);
  // Date normaliza días imposibles (p. ej. 30 de febrero); no presentarlos como otra fecha.
  return Number.isNaN(fecha.getTime()) || fecha.toISOString() !== valor.replace(/(\.\d{3})\d{3}Z$/, '$1Z')
    ? 'No disponible' : fechaSede.format(fecha);
}

export function etiquetaActor(valor) {
  return Object.hasOwn(actores, valor) ? actores[valor] : 'No disponible';
}

export function etiquetaResultado(valor) {
  return Object.hasOwn(resultados, valor) ? resultados[valor] : 'No disponible';
}

export function etiquetaCausa(valor) {
  return Object.hasOwn(causas, valor) ? `${causas[valor]} (${valor})` : 'No disponible';
}
