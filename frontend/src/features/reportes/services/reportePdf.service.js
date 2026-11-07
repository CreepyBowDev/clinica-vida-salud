import { api, obtenerEpocaSesion } from '../../../api/api';

const RECURSOS = {
  programadas: 'citas/programadas', 'por-medico': 'citas/por-medico',
  'por-especialidad': 'citas/por-especialidad', 'por-estado': 'citas/por-estado',
  'pacientes-registrados': 'pacientes/registrados', cantidades: 'citas/cantidades', 'agenda-diaria': 'agenda-diaria',
};

// FileReader también permite comprobar una porción sin materializar el PDF completo como texto.
function leer(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(new Error('No se pudo leer el archivo.'));
    reader.readAsText(blob);
  });
}
function vigente(epoch, signal) {
  if (signal?.aborted || epoch !== obtenerEpocaSesion()) {
    throw Object.assign(new Error('Descarga descartada.'), { code: 'ERR_CANCELED' });
  }
}
const MENSAJES = {
  401: 'Tu sesión ha finalizado. Inicia sesión para continuar.',
  403: 'No tienes permiso para descargar este reporte.',
  419: 'La protección de la sesión ha caducado. Comprueba tu sesión antes de volver a descargar.',
  422: 'No se pudo exportar. Revisa y aplica los filtros antes de volver a descargar.',
  429: 'Hay demasiadas solicitudes. Espera antes de volver a descargar.',
  500: 'No pudimos generar el PDF. Vuelve a intentarlo más tarde.',
};

export async function descargarReportePdf(tipo, filtros, signal) {
  const recurso = RECURSOS[tipo];
  if (!recurso) throw new Error('El reporte no está permitido.');
  const epoch = obtenerEpocaSesion();
  const params = Object.fromEntries(Object.entries(filtros).filter(([key, value]) =>
    !['page', 'per_page'].includes(key) && value !== '' && value !== undefined && value !== null));
  let response;
  try {
    response = await api.get(`/reportes/${recurso}/pdf`, {
      params, signal, responseType: 'blob', headers: { Accept: 'application/pdf, application/json' },
      timeout: 120000, sessionBound: true, sessionEpochExpected: epoch,
    });
  } catch (error) {
    // El interceptor compartido se ocupa del 401 y de invalidar la sesión actual.
    vigente(epoch, signal);
    if (error.code === 'ERR_CANCELED' || error.code === 'SESSION_SUPERSEDED') throw error;
    const status = error.response?.status;
    let body = error.response?.data;
    if (body instanceof Blob) {
      try { body = body.size <= 65536 ? JSON.parse(await leer(body)) : null; } catch { body = null; }
    }
    vigente(epoch, signal);
    let message = MENSAJES[status] ?? 'No pudimos descargar el PDF. Comprueba tu conexión y vuelve a intentarlo.';
    if (status === 422 && body?.error?.code === 'VALIDACION_ERROR') {
      const detail = body.error.details?.exportacion;
      if (Array.isArray(detail) && typeof detail[0] === 'string') message = detail[0];
    }
    const retry = error.response?.headers?.['retry-after'];
    const seconds = /^\d+$/.test(String(retry)) ? Number(retry) : Math.ceil((Date.parse(retry) - Date.now()) / 1000);
    throw Object.assign(new Error(message), { status, retryAfter: status === 429 ? Math.max(1, Number.isFinite(seconds) ? seconds : 60) : 0 });
  }
  vigente(epoch, signal);
  const blob = response.data;
  const mime = String(response.headers?.['content-type'] ?? '').split(';')[0].trim().toLowerCase();
  if (mime !== 'application/pdf' || !(blob instanceof Blob) || blob.size < 12 || blob.size > 8 * 1024 * 1024
    || (blob.type && blob.type.split(';')[0].toLowerCase() !== 'application/pdf')
    || !(await leer(blob.slice(0, 8))).startsWith('%PDF-') || !/%%EOF\s*$/.test(await leer(blob.slice(-1024)))) {
    throw new Error('La respuesta no es un PDF verificable. No se descargó ningún archivo.');
  }
  vigente(epoch, signal);
  const base = `reporte-${recurso.replaceAll('/', '-')}`;
  const disposition = String(response.headers?.['content-disposition'] ?? '');
  const candidate = disposition.match(/\bfilename="([^"]+)"/i)?.[1];
  const nombre = candidate && new RegExp(`^${base}-\\d{8}-\\d{6}\\.pdf$`).test(candidate) ? candidate : `${base}.pdf`;
  return { blob, nombre };
}
