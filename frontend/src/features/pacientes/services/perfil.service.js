import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

export async function consultarMiPerfil(signal) {
  const response = await api.get('/mi-perfil', { signal, sessionBound: true });
  return response.data.data;
}

export async function actualizarMiPerfil(datos) {
  if (typeof datos.actualizado_en_original !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(datos.actualizado_en_original)) throw new Error('Versión de perfil no válida.');
  const data = { actualizado_en_original: datos.actualizado_en_original };
  for (const name of ['telefono', 'direccion', 'correo_contacto', 'recordatorios_habilitados']) {
    if (Object.hasOwn(datos, name)) data[name] = datos[name];
  }
  if (Object.hasOwn(datos, 'contacto_emergencia')) {
    const contact = datos.contacto_emergencia;
    data.contacto_emergencia = contact ? { nombre: contact.nombre, parentesco: contact.parentesco, telefono: contact.telefono } : null;
  }
  await api.get(csrfURL);
  const response = await api.patch('/mi-perfil', data, { sessionBound: true });
  if (response.status !== 200 || !esIdentificador(response.data?.data?.id_paciente)) throw new Error('Actualización de perfil no confirmada.');
  return response.data.data;
}
