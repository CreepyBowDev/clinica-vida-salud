import { api, csrfURL } from '../../../api/api';

async function emitir(path, data) {
  await api.get(csrfURL);
  const response = await api.post(path, data, { sessionBound: true });
  const result = response.data?.data;
  if (response.status !== 202 || !['aceptado', 'simulado', 'fallido', 'pendiente'].includes(result?.envio)
    || typeof result.expira_en !== 'string' || !Number.isFinite(Date.parse(result.expira_en))) throw new Error('Emisión no confirmada.');
  return { envio: result.envio, expira_en: result.expira_en };
}

export function solicitarCambioCorreo({ correo_acceso, current_password }) {
  return emitir('/mi-perfil/correo', { correo_acceso: correo_acceso.trim().toLowerCase(), current_password });
}

export function reenviarCambioCorreo() {
  return emitir('/mi-perfil/correo/reenviar', {});
}

export async function verificarCambioCorreo({ codigo }) {
  await api.get(csrfURL);
  const response = await api.post('/mi-perfil/correo/verificar', { codigo }, { sessionBound: true });
  if (response.status !== 204) throw new Error('Cambio no confirmado.');
}
