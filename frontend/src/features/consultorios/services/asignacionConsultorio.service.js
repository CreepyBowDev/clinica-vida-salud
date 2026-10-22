import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

function path(id) {
  if (!esIdentificador(id)) throw new Error('Identificador de médico no válido.');
  return `/medicos/${id}/consultorio`;
}

export async function consultarAsignacion(id, signal) {
  return (await api.get(path(id), { signal, sessionBound: true })).data.data;
}

export async function consultarImpactoAsignacion(id, { id_consultorio, actualizado_en_original, page = 1, per_page = 20 }, signal) {
  const url = `${path(id)}/impacto`;
  if (!esIdentificador(id_consultorio)) throw new Error('Identificador de consultorio no válido.');
  await api.get(csrfURL, { signal });
  return (await api.post(url, { id_consultorio, actualizado_en_original, page, per_page }, { signal, sessionBound: true })).data;
}

export async function confirmarAsignacion(id, { id_consultorio, actualizado_en_original, huella_impacto, confirmar_traslados }) {
  const url = path(id);
  if (!esIdentificador(id_consultorio)) throw new Error('Identificador de consultorio no válido.');
  await api.get(csrfURL);
  return (await api.put(url, { id_consultorio, actualizado_en_original, huella_impacto, confirmar_traslados }, { sessionBound: true })).data.data;
}
