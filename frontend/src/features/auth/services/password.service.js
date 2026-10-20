import { api, csrfURL } from '../../../api/api';

export async function cambiarClave({ current_password, password, password_confirmation }) {
  await api.get(csrfURL);
  await api.put('/auth/password', { current_password, password, password_confirmation }, { sessionBound: true });
}

export async function solicitarRecuperacion({ correo_acceso }) {
  await api.get(csrfURL);
  await api.post('/auth/password/forgot', { correo_acceso });
}

export async function restablecerClave({ token, password, password_confirmation }) {
  await api.get(csrfURL);
  await api.post('/auth/password/reset', { token, password, password_confirmation });
}

export async function completarClaveTemporal({ password, password_confirmation }) {
  await api.get(csrfURL);
  await api.put('/auth/password/temporal', { password, password_confirmation }, { sessionBound: true });
}
