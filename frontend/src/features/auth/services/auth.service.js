import { api, csrfURL } from '../../../api/api';

export async function iniciarSesion({ correo_acceso, password }) {
  await api.get(csrfURL);
  const { data } = await api.post('/auth/login', { correo_acceso, password });
  return data.data;
}

export async function consultarSesion() {
  const { data } = await api.get('/auth/me', { sessionBound: true });
  return data.data;
}

export async function cerrarSesion() {
  await api.get(csrfURL);
  await api.post('/auth/logout', undefined, { sessionBound: true });
}
