import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

export async function emitirCodigoActivacion(id, identidad_verificada) {
  if (!esIdentificador(id)) throw new Error('Identificador de ficha no válido.');
  await api.get(csrfURL);
  const response = await api.post(`/pacientes/${id}/codigo-activacion`, { identidad_verificada }, { sessionBound: true });
  return response.data.data;
}
