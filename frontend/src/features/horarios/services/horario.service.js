import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

function path(id, horario) {
  if (!esIdentificador(id) || (horario !== undefined && !esIdentificador(horario))) throw new Error('Identificador no válido.');
  return `/medicos/${id}/horarios${horario === undefined ? '' : `/${horario}`}`;
}
const intervalo = data => Object.fromEntries(['dia_semana', 'hora_inicio', 'hora_fin'].filter(key => data[key] !== undefined).map(key => [key, data[key]]));
export async function listarHorarios({ id, activo, page = 1, per_page = 20 }, signal) {
  const params = { page, per_page };
  if (activo !== '' && activo !== undefined && activo !== null) params.activo = activo;
  return (await api.get(path(id), { params, signal, sessionBound: true })).data;
}
export async function crearHorario(id, data) {
  const url = path(id), payload = { ...intervalo(data), actualizado_en_original: data.actualizado_en_original };
  await api.get(csrfURL);
  return (await api.post(url, payload, { sessionBound: true })).data.data;
}
export async function actualizarHorario(id, horario, data) {
  const url = path(id, horario), payload = { ...intervalo(data), actualizado_en_original: data.actualizado_en_original };
  await api.get(csrfURL);
  return (await api.patch(url, payload, { sessionBound: true })).data.data;
}
export async function cambiarEstadoHorario(id, horario, { activo, actualizado_en_original }) {
  const url = `${path(id, horario)}/estado`;
  await api.get(csrfURL);
  return (await api.patch(url, { activo, actualizado_en_original }, { sessionBound: true })).data.data;
}
export async function consultarImpactoHorario(id, data, signal) {
  const url = `${path(id)}/impacto`;
  if (!['CREAR', 'EDITAR', 'ESTADO'].includes(data.accion)) throw new Error('Acción no válida.');
  if (data.accion !== 'CREAR' && !esIdentificador(data.id_horario)) throw new Error('Identificador de horario no válido.');
  const payload = { accion: data.accion, actualizado_en_original: data.actualizado_en_original, page: data.page ?? 1, per_page: data.per_page ?? 20,
    ...(data.accion === 'ESTADO' ? { activo: data.activo } : intervalo(data)),
    ...(data.accion === 'CREAR' ? {} : { id_horario: data.id_horario }) };
  await api.get(csrfURL, { signal });
  return (await api.post(url, payload, { signal, sessionBound: true })).data;
}
