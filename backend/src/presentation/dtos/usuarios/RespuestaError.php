<?php

namespace Clinica\presentation\dtos\usuarios;

use Clinica\shared\errors\AppError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RespuestaError
{
    public static function render(Throwable $error): JsonResponse
    {
        $status = match (true) {
            $error instanceof AppError => match ($error->codigo) {
                'AUTH_CREDENCIALES_INVALIDAS', 'AUTH_NO_AUTENTICADO' => 401,
                'ORIGEN_NO_PERMITIDO', 'ACCESO_DENEGADO', 'AUTH_CAMBIO_CLAVE_OBLIGATORIO' => 403,
                'AUTH_SESION_EXISTENTE', 'ACTIVACION_NO_PERMITIDA' => 409,
                'AUTH_LIMITE_SOLICITUDES' => 429,
                'AUTH_CLAVE_ACTUAL_INVALIDA' => 422,
                'INVITACION_INVALIDA', 'PERSONAL_ROL_INVALIDO', 'RECUPERACION_INVALIDA', 'REGISTRO_VERIFICACION_INVALIDA' => 422,
                'PERSONAL_CORREO_NO_DISPONIBLE', 'PERSONAL_VINCULO_INVALIDO', 'PERSONAL_INVITACION_NO_PERMITIDA' => 409,
                'RECURSO_NO_ENCONTRADO' => 404,
                'ESPECIALIDAD_NOMBRE_DUPLICADO', 'ESPECIALIDAD_EDICION_OBSOLETA' => 409,
                'CONSULTORIO_CODIGO_DUPLICADO', 'CONSULTORIO_EDICION_OBSOLETA',
                'CONSULTORIO_CON_ASIGNACIONES', 'CONSULTORIO_CON_OBLIGACIONES' => 409,
                'CONSULTORIO_NO_DISPONIBLE', 'ASIGNACION_IMPACTO_OBSOLETO', 'ASIGNACION_CONFIRMACION_REQUERIDA',
                'PLANIFICACION_CONFLICTO', 'ATENCION_NO_TRASLADABLE' => 409,
                'MEDICO_CONFLICTO_UNICIDAD', 'MEDICO_EDICION_OBSOLETA' => 409,
                'CONFIGURACION_EDICION_OBSOLETA', 'DURACION_INCOMPATIBLE', 'HORARIO_INTERVALO_INVALIDO', 'HORARIO_CITAS_SIN_COBERTURA' => 409,
                'CITA_TURNO_NO_DISPONIBLE' => 409,
                'CITA_REPROGRAMACION_SIN_CAMBIO', 'CITA_REPROGRAMACION_OBSOLETA',
                'CITA_EDICION_OBSOLETA', 'CITA_GESTION_NO_PERMITIDA',
                'CITA_PLAZO_GESTION_FINALIZADO', 'CITA_CON_ATENCION_DOCUMENTADA' => 409,
                'CITA_TRANSICION_NO_PERMITIDA', 'CITA_LLEGADA_FUERA_DE_PLAZO' => 409,
                'CITA_INASISTENCIA_ANTICIPADA', 'CITA_ATENCION_ANTICIPADA' => 409,
                'CITA_PRESENCIA_NO_VERIFICADA', 'CITA_AUSENCIA_NO_VERIFICADA', 'CITA_ATENCION_NO_VERIFICADA' => 422,
                'CLINICA_EDICION_OBSOLETA', 'CLINICA_CONSULTA_EXISTENTE', 'CLINICA_CITA_NO_PERMITIDA',
                'CLINICA_ATENCION_ANTICIPADA', 'CLINICA_LIMITE_VERSION' => 409,
                'CLINICA_ATENCION_NO_VERIFICADA' => 422,
                'LAB_EDICION_OBSOLETA', 'LAB_ESTADO_NO_PERMITIDO', 'LAB_DEVOLUCION_REQUERIDA',
                'LAB_DESTINO_NO_PERMITIDO', 'LAB_ARCHIVO_NO_DISPONIBLE', 'LAB_LIMITE_VERSION' => 409,
                'LAB_CORRESPONDENCIA_NO_VERIFICADA' => 422,
                'LAB_VALIDACION_NO_DISPONIBLE' => 503,
                'PUSH_NO_CONFIGURADO' => 503,
                'PAGO_NO_CONFIGURADO' => 503,
                'PAGO_EVENTO_NO_PERSISTIDO' => 503,
                'PAGO_VERIFICACION_NO_DISPONIBLE' => 503,
                'PAYPAL_RESULTADO_INCIERTO', 'PAGO_PROVEEDOR_INCOMPATIBLE',
                'PAGO_APROBACION_REQUERIDA', 'PAGO_CAPTURA_NO_PERMITIDA' => 409,
                'PAGO_FIRMA_INVALIDA', 'PAGO_EVENTO_INVALIDO' => 400,
                'PAGO_EVENTO_GRANDE' => 413,
                'PAGO_EVENTO_CONFLICTO', 'PAGO_EVIDENCIA_INCOMPATIBLE', 'PAGO_VENTANA_AGOTADA', 'PAGO_OPERACION_ACTIVA' => 409,
                'PAGO_CONTENIDO_NO_ADMITIDO' => 415,
                'PETICION_INVALIDA' => 400,
                'PAGO_PRECIO_YA_ASIGNADO', 'PAGO_CITA_NO_INCORPORADA', 'PAGO_NO_EXIGIBLE',
                'PAGO_REVISION_OBSOLETA', 'PAGO_IDEMPOTENCIA_CONFLICTO' => 409,
                'PUSH_SUSCRIPCION_OBSOLETA' => 409,
                'CITA_PROPUESTA_OBSOLETA', 'CITA_PACIENTE_SOLAPADO', 'CITA_PACIENTE_NO_DISPONIBLE' => 409,
                'MEDICO_VINCULO_INCOMPATIBLE' => 409,
                'MEDICO_SIN_ESPECIALIDADES_HABILITADAS', 'MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS' => 409,
                'PACIENTE_DOCUMENTO_NO_DISPONIBLE', 'PACIENTE_CONFLICTO_UNICIDAD' => 409,
                'PACIENTE_EDICION_OBSOLETA', 'PACIENTE_CI_NO_RETIRABLE' => 409,
                'PACIENTE_IDENTIDAD_NO_VERIFICADA' => 422,
                'CAMBIO_CORREO_INVALIDO' => 422,
                'CAMBIO_CORREO_NO_DISPONIBLE' => 409,
                'ULTIMO_ADMINISTRADOR', 'CUENTA_TRANSICION_INVALIDA', 'CUENTA_ROL_NO_PERMITIDO',
                'MEDICO_OBLIGACIONES_NO_RESUELTAS', 'PERSONAL_IDENTIDAD_INCOMPATIBLE', 'CREDENCIAL_TEMPORAL_NO_PERMITIDA' => 409,
                default => 500,
            },
            $error instanceof ValidationException => 422,
            $error instanceof AuthenticationException => 401,
            $error instanceof AuthorizationException => 403,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };
        [$codigo, $mensaje] = match ($status) {
            400 => ['PETICION_INVALIDA', 'La petición no es válida.'],
            401 => ['AUTH_NO_AUTENTICADO', 'Inicia sesión para continuar.'],
            403 => ['ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.'],
            404 => ['RECURSO_NO_ENCONTRADO', 'No se encontró el recurso solicitado.'],
            405 => ['METODO_NO_PERMITIDO', 'El método no está permitido.'],
            409 => ['AUTH_SESION_EXISTENTE', 'Cierra la sesión actual antes de iniciar otra.'],
            413 => ['ARCHIVO_DEMASIADO_GRANDE', 'La petición excede el tamaño permitido.'],
            419 => ['CSRF_INVALIDO', 'La protección de la sesión ha caducado. Actualiza la página.'],
            422 => ['VALIDACION_ERROR', 'Revisa los datos enviados.'],
            429 => ['AUTH_LIMITE_SOLICITUDES', 'Demasiadas solicitudes. Inténtalo más tarde.'],
            default => ['ERROR_INTERNO', 'No se pudo completar la operación.'],
        };
        if ($error instanceof AppError && $status !== 500) {
            $codigo = $error->codigo;
            $mensaje = $error->getMessage();
        }
        $body = ['code' => $codigo, 'message' => $mensaje];
        if ($error instanceof ValidationException) {
            $body['details'] = $error->errors();
        }
        if ($error instanceof AppError && $error->codigo === 'CITA_PROPUESTA_OBSOLETA' && $status === 409) {
            $cambios = $error->detalles['cambios'] ?? [];
            $body['details'] = ['cambios' => is_array($cambios) ? array_values(array_intersect(
                array_filter($cambios, 'is_string'), ['HORARIO', 'DURACION', 'CONSULTORIO', 'HABILITACION', 'DISPONIBILIDAD'])) : []];
        }
        $headers = ['Cache-Control' => 'no-store, private'];
        if ($error instanceof AppError && $error->esperaSegundos !== null) {
            $headers['Retry-After'] = (string) $error->esperaSegundos;
        }
        if ($status === 429 && $error instanceof HttpExceptionInterface) {
            $espera = $error->getHeaders()['Retry-After'] ?? null;
            if (is_numeric($espera) && (float) $espera >= 0 && (float) $espera <= 86400) {
                $headers['Retry-After'] = (string) (int) ceil((float) $espera);
            }
        }

        return new JsonResponse(['success' => false, 'error' => $body], $status, $headers);
    }
}
