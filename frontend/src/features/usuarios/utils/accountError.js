import { describirError } from '../../../utils/httpError';

const safeMessages = {
  ULTIMO_ADMINISTRADOR: 'El cambio dejaría a la clínica sin un Administrador habilitado. Debe conservarse otro Administrador con acceso normal.',
  MEDICO_OBLIGACIONES_NO_RESUELTAS: 'No se puede retirar el rol Médico. Se requiere inactividad profesional expresa y ausencia comprobable de obligaciones asistenciales o documentación pendiente.',
  PERSONAL_IDENTIDAD_INCOMPATIBLE: 'Comprueba presencialmente la identidad y la correspondencia del registro profesional. No se modificaron sus datos.',
  CUENTA_TRANSICION_INVALIDA: 'La cuenta no admite este cambio de acceso. Actualiza el detalle y comprueba su verificación y vínculo.',
  CUENTA_ROL_NO_PERMITIDO: 'La cuenta no admite este cambio de rol. Actualiza el detalle para consultar su estado actual.',
  CREDENCIAL_TEMPORAL_NO_PERMITIDA: 'La cuenta no admite una credencial temporal. Solo corresponde a personal activo y previamente verificado.',
  PERSONAL_CORREO_NO_DISPONIBLE: 'El correo de acceso no está disponible. Consulta la cuenta antes de volver a crearla.',
  PERSONAL_VINCULO_INVALIDO: 'El registro profesional no está disponible para esta vinculación.',
  PERSONAL_ROL_INVALIDO: 'El rol seleccionado no está disponible.',
  PERSONAL_INVITACION_NO_PERMITIDA: 'La cuenta ya no admite una invitación inicial. Actualiza su detalle.',
};

export function describirErrorCuenta(error) {
  const failure = describirError(error, { operation: 'account', allowedFields: ['rol', 'estado_cuenta', 'correo_acceso', 'nombres_personal', 'apellidos_personal', 'id_medico', 'identidad_verificada', 'page', 'per_page'] });
  if (failure.status === 404) failure.message = 'No se encontró una cuenta disponible para esta consulta.';
  if ([409, 422].includes(failure.status)) failure.message = safeMessages[error.response?.data?.error?.code] || failure.message;
  return failure;
}
