import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

export async function listarEspecialidades({ nombre, activa, page = 1, per_page = 20 } = {}, signal) {
  const params = { page, per_page };
  if (nombre?.trim()) params.nombre = nombre.trim();
  if (activa !== '' && activa !== undefined && activa !== null) params.activa = activa;
  const response = await api.get('/especialidades', { params, signal, sessionBound: true });
  return response.data;
}

function path(id) {
  if (!esIdentificador(id)) throw new Error('Identificador de especialidad no válido.');
  return `/especialidades/${id}`;
}

export async function consultarEspecialidad(id, signal) {
  const response = await api.get(path(id), { signal, sessionBound: true });
  return response.data.data;
}

export async function crearEspecialidad({ nombre, descripcion }) {
  const data = { nombre: nombre.trim(), descripcion: descripcion?.trim() || null };
  await api.get(csrfURL);
  const response = await api.post('/especialidades', data, { sessionBound: true });
  return response.data.data;
}

export async function actualizarEspecialidad(id, { nombre, descripcion, actualizado_en_original }) {
  const url = path(id);
  const data = { actualizado_en_original };
  if (nombre !== undefined) data.nombre = nombre.trim();
  if (descripcion !== undefined) data.descripcion = descripcion?.trim() || null;
  await api.get(csrfURL);
  const response = await api.patch(url, data, { sessionBound: true });
  return response.data.data;
}

export async function cambiarEstadoEspecialidad(id, { activa, actualizado_en_original }) {
  const url = `${path(id)}/estado`;
  await api.get(csrfURL);
  const response = await api.patch(url, { activa, actualizado_en_original }, { sessionBound: true });
  return response.data.data;
}
