import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

export const camposMedico = ['registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo_contacto'];

function path(id) {
  if (!esIdentificador(id)) throw new Error('Identificador de médico no válido.');
  return `/medicos/${id}`;
}

function datosProfesionales(data) {
  return Object.fromEntries(camposMedico.filter(key => data[key] !== undefined).map(key => [key,
    key === 'correo_contacto' ? data[key].trim().toLowerCase() : data[key].trim()]));
}

export async function listarMedicos({ nombre, activo, id_especialidad, page = 1, per_page = 20 } = {}, signal) {
  const params = { page, per_page };
  if (nombre?.trim()) params.nombre = nombre.trim();
  if (activo !== '' && activo !== undefined && activo !== null) params.activo = activo;
  if (id_especialidad) params.id_especialidad = id_especialidad;
  const response = await api.get('/medicos', { params, signal, sessionBound: true });
  return response.data;
}

export async function consultarMedico(id, signal) {
  const response = await api.get(path(id), { signal, sessionBound: true });
  return response.data.data;
}

export async function crearMedico(data) {
  const payload = { ...datosProfesionales(data), especialidades: [...data.especialidades] };
  await api.get(csrfURL);
  const response = await api.post('/medicos', payload, { sessionBound: true });
  return response.data.data;
}

export async function actualizarMedico(id, data) {
  const url = path(id);
  const payload = { ...datosProfesionales(data), actualizado_en_original: data.actualizado_en_original };
  await api.get(csrfURL);
  const response = await api.patch(url, payload, { sessionBound: true });
  return response.data.data;
}

export async function cambiarEstadoMedico(id, { activo, actualizado_en_original }) {
  const url = `${path(id)}/estado`;
  await api.get(csrfURL);
  const response = await api.patch(url, { activo, actualizado_en_original }, { sessionBound: true });
  return response.data.data;
}

export async function cambiarEspecialidadesMedico(id, { especialidades, actualizado_en_original }) {
  const url = `${path(id)}/especialidades`;
  const data = { especialidades: [...especialidades], actualizado_en_original };
  await api.get(csrfURL);
  const response = await api.put(url, data, { sessionBound: true });
  return response.data.data;
}
