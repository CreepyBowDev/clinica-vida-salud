import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

export async function listarPacientes({ documento_identidad, nombres, apellidos, activo, page = 1, per_page = 20 } = {}, signal) {
  const params = { page, per_page };
  if (documento_identidad) params.documento_identidad = documento_identidad;
  if (nombres) params.nombres = nombres;
  if (apellidos) params.apellidos = apellidos;
  if (activo !== '' && activo !== undefined && activo !== null) params.activo = activo;
  const response = await api.get('/pacientes', { params, signal, sessionBound: true });
  return response.data;
}

export async function consultarPaciente(id, signal) {
  if (!esIdentificador(id)) throw new Error('Identificador de ficha no válido.');
  const response = await api.get(`/pacientes/${id}`, { signal, sessionBound: true });
  return response.data.data;
}

export async function crearPaciente({ documento_identidad, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo_contacto, direccion, contacto_emergencia, posibles_duplicados_revisados }) {
  const optional = value => typeof value === 'string' && value.trim() ? value.trim() : null;
  const data = { documento_identidad: optional(documento_identidad), nombres: nombres.trim(), apellidos: apellidos.trim(), fecha_nacimiento,
    sexo: optional(sexo), telefono: telefono.trim(), correo_contacto: optional(correo_contacto), direccion: optional(direccion),
    contacto_emergencia: contacto_emergencia ? { nombre: contacto_emergencia.nombre.trim(), parentesco: contacto_emergencia.parentesco.trim(), telefono: contacto_emergencia.telefono.trim() } : null };
  if (!data.documento_identidad) data.posibles_duplicados_revisados = posibles_duplicados_revisados === true;
  await api.get(csrfURL);
  const response = await api.post('/pacientes', data, { sessionBound: true });
  if (response.status !== 201 || !esIdentificador(response.data?.data?.id_paciente)) throw new Error('Alta no confirmada.');
  return response.data.data;
}

function exigirVersion(id, version) {
  if (!esIdentificador(id) || typeof version !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(version)) throw new Error('Referencia de ficha no válida.');
}

export async function actualizarPaciente(id, datos) {
  exigirVersion(id, datos.actualizado_en_original);
  const data = { actualizado_en_original: datos.actualizado_en_original };
  for (const name of ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_contacto', 'direccion', 'motivo', 'identidad_verificada']) {
    if (Object.hasOwn(datos, name)) data[name] = datos[name];
  }
  if (Object.hasOwn(datos, 'contacto_emergencia')) {
    const contact = datos.contacto_emergencia;
    data.contacto_emergencia = contact ? { nombre: contact.nombre, parentesco: contact.parentesco, telefono: contact.telefono } : null;
  }
  await api.get(csrfURL);
  const response = await api.patch(`/pacientes/${id}`, data, { sessionBound: true });
  if (response.status !== 200 || response.data?.data?.id_paciente !== id) throw new Error('Actualización no confirmada.');
  return response.data.data;
}

export async function cambiarEstadoPaciente(id, { activo, motivo, actualizado_en_original }) {
  exigirVersion(id, actualizado_en_original);
  await api.get(csrfURL);
  const response = await api.patch(`/pacientes/${id}/estado`, { activo, motivo, actualizado_en_original }, { sessionBound: true });
  if (response.status !== 200 || response.data?.data?.id_paciente !== id) throw new Error('Cambio de estado no confirmado.');
  return response.data.data;
}
