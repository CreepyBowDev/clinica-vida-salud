import { describirError } from '../../../utils/httpError';

export function laboratorioError(error) {
  const failure = describirError(error, { operation: 'query', allowedFields: [] });
  failure.message = ({ 400: 'La consulta no es válida. Revisa los filtros o el recurso solicitado.',
    404: 'La solicitud o el documento no está disponible o no tienes acceso.',
    409: 'El documento cambió o no está disponible. Vuelve a consultar la solicitud antes de descargar.',
    419: 'La protección de la sesión ha caducado. Comprueba tu sesión y vuelve a consultar.',
    422: 'La consulta no es válida. Revisa las fechas y el recurso solicitado.' })[failure.status] ?? failure.message;
  if (!error.response || failure.status >= 500) failure.message = 'No pudimos obtener la información de laboratorio. Comprueba tu conexión y vuelve a consultar.';
  if (failure.status === 429) {
    const value = error.response?.headers?.['retry-after'];
    const date = typeof value === 'string' && !Number.isFinite(Number(value)) ? Date.parse(value) : NaN;
    if (Number.isFinite(date)) failure.retryAfter = Math.max(0, Math.ceil((date - Date.now()) / 1000));
  }
  return failure;
}
