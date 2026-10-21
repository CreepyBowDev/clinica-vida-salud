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
                'MEDICO_CONFLICTO_UNICIDAD', 'MEDICO_EDICION_OBSOLETA' => 409,
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
        $headers = ['Cache-Control' => 'no-store, private'];
        if ($error instanceof AppError && $error->esperaSegundos !== null) {
            $headers['Retry-After'] = (string) $error->esperaSegundos;
        }

        return new JsonResponse(['success' => false, 'error' => $body], $status, $headers);
    }
}
