import { api, csrfURL } from '../../../api/api';

export function esIdentificador(value) {
  return typeof value === 'string' && /^[1-9][0-9]{0,18}$/.test(value) && BigInt(value) <= 9223372036854775807n;
}

function exigirId(id) {
  if (!esIdentificador(id)) throw new Error('Identificador no válido.');
  return id;
}

export async function listarCuentas({ rol, estado_cuenta, correo_acceso, page = 1, per_page = 20 } = {}, signal) {
  const params = { page, per_page };
  if (rol) params.rol = rol;
  if (estado_cuenta) params.estado_cuenta = estado_cuenta;
  if (correo_acceso) params.correo_acceso = correo_acceso;
  const response = await api.get('/usuarios', { params, signal, sessionBound: true });
  return response.data;
}

export async function consultarCuenta(id, signal) {
  const response = await api.get(`/usuarios/${exigirId(id)}`, { signal, sessionBound: true });
  return response.data.data;
}

export async function crearPersonal({ rol, correo_acceso, nombres_personal, apellidos_personal, id_medico }) {
  const data = { rol, correo_acceso };
  if (rol === 'Médico') data.id_medico = exigirId(id_medico);
  else Object.assign(data, { nombres_personal, apellidos_personal });
  await api.get(csrfURL);
  const response = await api.post('/personal', data, { sessionBound: true });
  return response.data;
}

export async function reenviarInvitacion(id) {
  exigirId(id);
  await api.get(csrfURL);
  const response = await api.post(`/personal/${id}/invitacion`, {}, { sessionBound: true });
  return response.data;
}

export async function cambiarEstadoCuenta(id, estado_cuenta) {
  exigirId(id);
  await api.get(csrfURL);
  const response = await api.patch(`/usuarios/${id}/estado`, { estado_cuenta }, { sessionBound: true });
  return response.data.data;
}

export async function cambiarRolCuenta(id, { rol, id_medico, identidad_verificada }) {
  exigirId(id);
  const data = { rol };
  if (rol === 'Médico') {
    data.id_medico = exigirId(id_medico);
    if (identidad_verificada === true) data.identidad_verificada = true;
  }
  await api.get(csrfURL);
  const response = await api.patch(`/usuarios/${id}/rol`, data, { sessionBound: true });
  return response.data.data;
}

export async function emitirCredencialTemporal(id, identidad_verificada) {
  exigirId(id);
  await api.get(csrfURL);
  const response = await api.post(`/usuarios/${id}/credencial-temporal`, { identidad_verificada }, { sessionBound: true });
  return response.data.data;
}
