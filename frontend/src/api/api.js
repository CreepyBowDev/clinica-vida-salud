import axios from 'axios';

const configuredURL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const baseURL = new URL(configuredURL, window.location.origin);
if (!['http:', 'https:'].includes(baseURL.protocol) || baseURL.username || baseURL.password
  || baseURL.search || baseURL.hash) {
  throw new Error('VITE_API_URL debe ser una URL HTTP(S) sin credenciales, query ni fragmento.');
}

export const csrfURL = new URL('/sanctum/csrf-cookie', baseURL.origin).href;

export const api = axios.create({
  baseURL: baseURL.href.replace(/\/$/, ''),
  withCredentials: true,
  withXSRFToken: true,
  timeout: 15000,
  headers: { Accept: 'application/json' },
});

let sessionEpoch = 0;
const invalidationListeners = new Set();
const sessionChangeListeners = new Set();

export function obtenerEpocaSesion() { return sessionEpoch; }
export function escucharCambioSesion(listener) {
  sessionChangeListeners.add(listener);
  return () => sessionChangeListeners.delete(listener);
}
export function avanzarSesion() { sessionEpoch += 1; sessionChangeListeners.forEach(listener => listener()); }

export function escucharSesionInvalidada(listener) {
  invalidationListeners.add(listener);
  return () => invalidationListeners.delete(listener);
}

api.interceptors.request.use((config) => {
  if (config.sessionBound && config.sessionEpochExpected !== undefined && config.sessionEpochExpected !== sessionEpoch) {
    throw new axios.AxiosError('El contexto de sesión cambió. Vuelve a consultar.', 'SESSION_SUPERSEDED', config);
  }
  if (config.sessionBound) config.sessionEpoch = sessionEpoch;
  return config;
});

api.interceptors.response.use((response) => {
  if (response.config.sessionBound && response.config.sessionEpoch !== sessionEpoch) {
    return Promise.reject(new axios.AxiosError('Respuesta de una sesión anterior descartada.', 'SESSION_SUPERSEDED'));
  }
  return response;
}, (error) => {
  const config = error.config;
  if (config?.sessionBound && config.sessionEpochExpected !== undefined && config.sessionEpochExpected !== sessionEpoch) {
    return Promise.reject(new axios.AxiosError('Respuesta de una sesión anterior descartada.', 'SESSION_SUPERSEDED', config));
  }
  // Un login 401 no expira otra sesión. Tampoco una respuesta tardía de un contexto anterior.
  if (config?.sessionBound && config.sessionEpoch === sessionEpoch && error.response?.status === 401) {
    avanzarSesion();
    invalidationListeners.forEach((listener) => listener());
  }
  return Promise.reject(error);
});

// No se reintentan escrituras: un timeout puede ocurrir después del COMMIT.
