import { describirError } from '../../../utils/httpError';

export function esperaPago(headers) {
  const value = headers?.['retry-after'];
  if (value === undefined || value === null || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.max(0, Math.ceil(n)) : Math.max(0, Math.ceil((Date.parse(value) - Date.now()) / 1000)) || 0;
}
export function errorPago(error) {
  const result = describirError(error, { operation: 'pagos', allowedFields: ['monto_bob', 'fecha_desde', 'fecha_hasta', 'proveedor', 'resultado', 'page', 'per_page'] });
  result.retryAfter = esperaPago(error.response?.headers);
  result.message = ({ 400: 'La solicitud financiera no es válida.', 404: 'El recurso financiero no está disponible o no tienes acceso.',
    409: 'Las condiciones cambiaron. Consulta el estado vigente antes de continuar; no inicies otro pago para resolver la incertidumbre.',
    419: 'La protección de la sesión caducó. Comprueba el resultado antes de volver a enviar.',
    503: 'La operación financiera no está disponible. Si ya enviaste una solicitud, su resultado puede seguir pendiente.' })[result.status] ?? result.message;
  return result;
}
