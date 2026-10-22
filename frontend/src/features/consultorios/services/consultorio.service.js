import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

export const camposConsultorio = ['codigo_consultorio', 'nombre'];

function path(id) {
  if (!esIdentificador(id)) throw new Error('Identificador de consultorio no válido.');
  return `/consultorios/${id}`;
}

export async function listarConsultorios({ q, activo, page = 1, per_page = 20 } = {}, signal) {
  const params = { page, per_page };
  if (q?.trim()) params.q = q.trim();
  if (activo !== '' && activo !== undefined && activo !== null) params.activo = activo;
  return (await api.get('/consultorios', { params, signal, sessionBound: true })).data;
}

export async function consultarConsultorio(id, signal) {
  return (await api.get(path(id), { signal, sessionBound: true })).data.data;
}

export async function crearConsultorio(data) {
  const payload = Object.fromEntries(camposConsultorio.map(key => [key, data[key].trim()]));
  await api.get(csrfURL);
  return (await api.post('/consultorios', payload, { sessionBound: true })).data.data;
}

export async function actualizarConsultorio(id, data) {
  const url = path(id);
  const payload = { ...Object.fromEntries(camposConsultorio.filter(key => data[key] !== undefined).map(key => [key, data[key].trim()])),
    actualizado_en_original: data.actualizado_en_original };
  await api.get(csrfURL);
  return (await api.patch(url, payload, { sessionBound: true })).data.data;
}

export async function cambiarEstadoConsultorio(id, { activo, actualizado_en_original }) {
  const url = `${path(id)}/estado`;
  await api.get(csrfURL);
  return (await api.patch(url, { activo, actualizado_en_original }, { sessionBound: true })).data.data;
}
