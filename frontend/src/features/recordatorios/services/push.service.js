import { api, csrfURL, obtenerEpocaSesion } from '../../../api/api';

function exigir(value) { if (!value) throw new Error('No se pudo verificar la autorización del dispositivo.'); }
export function clavePush(value) {
  exigir(typeof value === 'string' && /^[A-Za-z0-9_-]{87}$/.test(value));
  const bytes = Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '='), c => c.charCodeAt(0));
  exigir(bytes.length === 65 && bytes[0] === 4);
  return bytes;
}
export function comprobarSesionPush(epoca, signal) {
  if (signal?.aborted || epoca !== obtenerEpocaSesion()) throw Object.assign(new Error('La sesión cambió.'), { code: 'SESSION_SUPERSEDED' });
}
export async function consultarConfiguracionPush(signal, epoca = obtenerEpocaSesion()) {
  comprobarSesionPush(epoca, signal);
  const r = await api.get('/push/configuracion', { signal, sessionBound: true, sessionEpochExpected: epoca });
  comprobarSesionPush(epoca, signal);
  const v = r.data?.data;
  exigir(r.status === 200 && typeof v?.habilitado === 'boolean');
  if (v.habilitado) clavePush(v.clave_publica_vapid);
  else exigir(v.clave_publica_vapid === null);
  return { habilitado: v.habilitado, clave_publica_vapid: v.clave_publica_vapid };
}
export async function autorizarPush(suscripcion, signal, epoca = obtenerEpocaSesion()) {
  comprobarSesionPush(epoca, signal);
  // Proyección explícita: ni identificadores de titular ni datos del navegador adicionales.
  const body = { permiso_otorgado: true, suscripcion: { endpoint: suscripcion.endpoint,
    expirationTime: suscripcion.expirationTime ?? null,
    keys: { p256dh: suscripcion.keys?.p256dh, auth: suscripcion.keys?.auth } } };
  const options = { signal, sessionBound: true, sessionEpochExpected: epoca };
  await api.get(csrfURL, options);
  comprobarSesionPush(epoca, signal);
  const r = await api.put('/push/suscripcion', body, options);
  comprobarSesionPush(epoca, signal);
  const v = r.data?.data;
  exigir([200, 201].includes(r.status) && /^[1-9][0-9]{0,18}$/.test(v?.id_suscripcion)
    && v.actual === true && v.activa === true && Number.isInteger(v.version) && v.version > 0);
  return { activa: true };
}

const idValido = value => typeof value === 'string' && /^[1-9][0-9]{0,18}$/.test(value) && BigInt(value) <= 9223372036854775807n;
const fechaValida = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(value) && Number.isFinite(Date.parse(value));

export async function listarSuscripcionesPush(page = 1, signal, epoca = obtenerEpocaSesion()) {
  exigir(Number.isInteger(page) && page > 0 && page <= 2147483647);
  comprobarSesionPush(epoca, signal);
  const r = await api.get('/push/suscripciones', { params: { page, per_page: 20 }, signal, sessionBound: true, sessionEpochExpected: epoca });
  comprobarSesionPush(epoca, signal);
  const { data, meta } = r.data || {};
  exigir(r.status === 200 && Array.isArray(data) && meta && ['current_page', 'per_page', 'last_page', 'total'].every(k => Number.isSafeInteger(meta[k]))
    && meta.current_page === page && meta.per_page === 20 && meta.total >= 0 && meta.last_page === Math.max(1, Math.ceil(meta.total / 20))
    && data.length <= 20 && new Set(data.map(v => v?.id_suscripcion)).size === data.length);
  const items = data.map(v => {
    exigir(idValido(v?.id_suscripcion) && typeof v.actual === 'boolean' && typeof v.activa === 'boolean'
      && Number.isInteger(v.version) && v.version > 0 && fechaValida(v.autorizada_en)
      && (v.revocada_en === null || fechaValida(v.revocada_en)));
    return { id_suscripcion: v.id_suscripcion, actual: v.actual, activa: v.activa, version: v.version,
      autorizada_en: v.autorizada_en, revocada_en: v.revocada_en };
  });
  return { data: items, meta: { current_page: meta.current_page, per_page: meta.per_page, total: meta.total, last_page: meta.last_page } };
}

// null identifica la instalación por cookie HttpOnly; nunca se envían endpoint, claves ni titular.
export async function revocarSuscripcionPush(id = null, signal, epoca = obtenerEpocaSesion()) {
  exigir(id === null || idValido(id));
  comprobarSesionPush(epoca, signal);
  const options = { signal, sessionBound: true, sessionEpochExpected: epoca };
  await api.get(csrfURL, options);
  comprobarSesionPush(epoca, signal);
  const r = await api.delete(id === null ? '/push/suscripcion' : `/push/suscripciones/${id}`, options);
  comprobarSesionPush(epoca, signal);
  exigir(r.status === 204);
}
