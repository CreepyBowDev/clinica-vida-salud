import { api, csrfURL } from '../../../api/api';

export async function consultarConfiguracionCitas(signal) {
  return (await api.get('/configuracion-citas', { signal, sessionBound: true })).data.data;
}

export async function consultarImpactoDuracion({ duracion_turno_minutos, actualizado_en_original, page = 1, per_page = 20 }, signal) {
  await api.get(csrfURL, { signal });
  return (await api.post('/configuracion-citas/impacto', { duracion_turno_minutos, actualizado_en_original, page, per_page }, { signal, sessionBound: true })).data;
}

export async function actualizarDuracion({ duracion_turno_minutos, actualizado_en_original }) {
  await api.get(csrfURL);
  return (await api.patch('/configuracion-citas', { duracion_turno_minutos, actualizado_en_original }, { sessionBound: true })).data.data;
}
