import { api, csrfURL } from '../../../api/api';

export async function aceptarInvitacion({ token, password, password_confirmation }) {
  await api.get(csrfURL);
  await api.post('/auth/invitaciones/aceptar', { token, password, password_confirmation });
}
