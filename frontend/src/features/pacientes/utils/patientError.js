import { describirError } from '../../../utils/httpError';

export function describirErrorPaciente(error, { writing = false, operation = 'create' } = {}) {
  const failure = describirError(error, { operation: 'patient', allowedFields: ['documento_identidad', 'nombres', 'apellidos', 'activo', 'page', 'per_page', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_contacto', 'direccion', 'posibles_duplicados_revisados', 'contacto_emergencia', 'contacto_emergencia.nombre', 'contacto_emergencia.parentesco', 'contacto_emergencia.telefono', 'motivo', 'identidad_verificada', 'actualizado_en_original', 'recordatorios_habilitados'] });
  if (failure.status === 404) failure.message = 'No se encontró una ficha disponible para esta consulta.';
  if (!error.response && !writing) failure.message = 'No pudimos consultar los pacientes. Comprueba tu conexión y vuelve a intentarlo.';
  if (!error.response && !writing && operation === 'profile') failure.message = 'No pudimos consultar tu perfil. Comprueba tu conexión y vuelve a intentarlo.';
  if (writing && (!failure.status || failure.status >= 500)) {
    failure.uncertain = true;
    failure.message = operation === 'create' ? 'No pudimos confirmar el resultado del alta. La ficha podría haberse creado. Consulta el registro antes de volver a enviarla.' : 'No pudimos confirmar el resultado del cambio. Consulta la ficha actual antes de decidir si corresponde otro envío.';
  }
  if (writing && failure.status === 409) failure.message = {
    PACIENTE_DOCUMENTO_NO_DISPONIBLE: 'El CI no está disponible o tiene un proceso pendiente. Revisa las fichas existentes antes de continuar.',
    PACIENTE_CONFLICTO_UNICIDAD: 'No se pudo guardar la ficha por un conflicto de unicidad. Consulta los registros antes de volver a intentarlo.',
    PACIENTE_EDICION_OBSOLETA: 'La ficha cambió desde que la consultaste. Tu borrador se conserva; revisa la versión actual antes de continuar.',
    PACIENTE_CI_NO_RETIRABLE: 'No se puede retirar un CI informado. Comprueba presencialmente el documento para corregirlo.',
    PACIENTE_IDENTIDAD_NO_VERIFICADA: 'La corrección de identidad exige comprobación presencial y motivo administrativo.',
  }[error.response?.data?.error?.code] || 'No se pudo guardar la ficha en su estado actual. Consulta los registros antes de volver a intentarlo.';
  failure.stale = failure.status === 409 && error.response?.data?.error?.code === 'PACIENTE_EDICION_OBSOLETA';
  if (failure.status === 419) failure.message = 'La protección de la sesión ha caducado. Comprueba tu sesión antes de volver a consultar.';
  return failure;
}
