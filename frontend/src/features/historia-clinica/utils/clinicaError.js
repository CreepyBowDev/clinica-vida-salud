import { describirError } from '../../../utils/httpError';

export function describirErrorClinico(error, allowedFields = []) {
  const result = describirError(error, { operation: 'query', allowedFields });
  if (result.status === 429) {
    const value = error.response?.headers?.['retry-after'];
    // Retry-After admite segundos o fecha HTTP; no es un reloj de elegibilidad clínica.
    const fecha = typeof value === 'string' && !Number.isFinite(Number(value)) ? Date.parse(value) : NaN;
    if (Number.isFinite(fecha)) result.retryAfter = Math.max(0, Math.ceil((fecha - Date.now()) / 1000));
  }
  return result;
}
