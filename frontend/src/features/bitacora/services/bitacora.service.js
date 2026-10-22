import { api } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

const filtrosPermitidos = ['fecha_desde', 'fecha_hasta', 'id_usuario_responsable', 'id_usuario_objetivo',
  'tipo_actor', 'modulo', 'operacion', 'tipo_evento', 'resultado', 'direccion_ip', 'entidad_referida', 'registro_referido'];

export async function listarBitacora(filtros = {}, signal) {
  const { page = 1, per_page = 20 } = filtros;
  const params = { page, per_page };
  for (const campo of filtrosPermitidos) {
    const valor = typeof filtros[campo] === 'string' ? filtros[campo].replace(/^ +| +$/g, '') : filtros[campo];
    if (valor !== '' && valor !== undefined && valor !== null) params[campo] = valor;
  }
  const response = await api.get('/bitacora', { params, signal, sessionBound: true });
  return response.data;
}

export async function consultarEvento(id, signal) {
  if (!esIdentificador(id)) throw new Error('Identificador de evento no válido.');
  const response = await api.get(`/bitacora/${id}`, { signal, sessionBound: true });
  return response.data.data;
}
