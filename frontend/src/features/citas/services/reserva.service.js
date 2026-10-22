import { api, csrfURL } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';

const pagina = ({ page = 1, per_page = 20 }) => ({ page, per_page });
function paginaValida(result, lista, validarItem) {
  const meta = result?.meta;
  if (!Array.isArray(lista) || !lista.every(validarItem) || !meta
    || !Number.isInteger(meta.current_page) || meta.current_page < 1
    || !Number.isInteger(meta.last_page) || meta.last_page < 1
    || !Number.isInteger(meta.total) || meta.total < 0) throw new Error('No se pudo verificar la consulta de reserva.');
  return result;
}
function id(value) {
  if (!esIdentificador(value)) throw new Error('La selección no es válida.');
  return value;
}
export async function listarEspecialidadesReserva(filtros = {}, signal) {
  const params = pagina(filtros);
  if (filtros.nombre?.trim()) params.nombre = filtros.nombre.trim();
  const result = (await api.get('/reservas/especialidades', { params, signal, sessionBound: true })).data;
  return paginaValida(result, result?.data, item => item && esIdentificador(item.id_especialidad) && texto(item.nombre));
}
export async function listarMedicosReserva(filtros, signal) {
  const params = { ...pagina(filtros), id_especialidad: id(filtros.id_especialidad) };
  for (const campo of ['nombre', 'consultorio']) {
    if (filtros[campo]?.trim()) params[campo] = filtros[campo].trim();
  }
  const result = (await api.get('/reservas/medicos', { params, signal, sessionBound: true })).data;
  return paginaValida(result, result?.data, item => item && esIdentificador(item.id_medico)
    && texto(item.codigo_medico) && texto(item.nombres) && texto(item.apellidos) && salaValida(item.consultorio));
}
export async function consultarDisponibilidad(filtros, signal) {
  const params = { ...pagina(filtros), id_especialidad: id(filtros.id_especialidad), id_medico: id(filtros.id_medico), fecha: filtros.fecha };
  if (filtros.id_paciente !== undefined) params.id_paciente = id(filtros.id_paciente);
  const result = (await api.get('/reservas/disponibilidad', { params, signal, sessionBound: true })).data;
  const d = result?.data;
  if (!d || d.fecha !== params.fecha || d.medico?.id_medico !== params.id_medico || d.especialidad?.id_especialidad !== params.id_especialidad
    || !texto(d.medico.nombres) || !texto(d.medico.apellidos) || !texto(d.especialidad.nombre) || !salaValida(d.consultorio)
    || d.zona_horaria !== 'America/La_Paz' || typeof d.paciente_reservable !== 'boolean'
    || !Number.isInteger(d.duracion_minutos) || d.duracion_minutos < 1) throw new Error('No se pudo verificar la disponibilidad.');
  return paginaValida(result, d.turnos, slot => slot && instante(slot.inicio_at) && instante(slot.fin_at)
    && typeof slot.hora_inicio === 'string' && /^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/.test(slot.hora_inicio)
    && typeof slot.revision_turno === 'string' && /^[a-f0-9]{64}$/.test(slot.revision_turno));
}

const instante = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(value) && Number.isFinite(Date.parse(value));
const texto = value => typeof value === 'string' && value.length > 0;
const salaValida = sala => sala && esIdentificador(sala.id_consultorio) && texto(sala.codigo_consultorio) && texto(sala.nombre);
export function normalizarMotivo(value) {
  if (value !== undefined && value !== null && typeof value !== 'string') throw new Error('Motivo no válido.');
  const result = value?.trim() ?? '';
  if ([...result].length > 500 || /\p{C}/u.test(result)) throw new Error('El motivo debe tener hasta 500 caracteres y no contener saltos de línea ni caracteres de control.');
  return result === '' ? null : result;
}
function exigirPropuesta(value) {
  if (!texto(value) || value.length > 16384) throw new Error('La propuesta no es válida.');
}
function citaValida(cita) {
  return cita && ['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio'].every(key => esIdentificador(cita[key]))
    && texto(cita.codigo_cita) && ['Programada', 'Confirmada', 'Cancelada', 'Atendida', 'Inasistencia'].includes(cita.estado)
    && cita.modalidad === 'Presencial' && Number.isInteger(cita.duracion_minutos) && cita.duracion_minutos > 0
    && instante(cita.inicio_at) && instante(cita.fin_at) && texto(cita.sede_atencion) && texto(cita.consultorio_atencion)
    && (cita.motivo_breve === null || typeof cita.motivo_breve === 'string');
}
async function postReserva(path, payload, validar) {
  // Distinguir fallo previo de CSRF de un POST que pudo llegar a COMMIT.
  try { await api.get(csrfURL, { sessionBound: true }); }
  catch (error) { error.reservaEnviada = false; throw error; }
  try {
    const response = await api.post(path, payload, { sessionBound: true });
    if (!validar(response)) throw new Error('No se pudo verificar la respuesta de la reserva.');
    return response.data;
  } catch (error) { error.reservaEnviada = true; throw error; }
}
export async function proponerReserva(datos) {
  const payload = { id_especialidad: id(datos.id_especialidad), id_medico: id(datos.id_medico), fecha: datos.fecha,
    hora_inicio: datos.hora_inicio, revision_turno: datos.revision_turno, motivo_breve: normalizarMotivo(datos.motivo_breve) };
  if (datos.id_paciente !== undefined) payload.id_paciente = id(datos.id_paciente);
  const result = await postReserva('/reservas/propuesta', payload, response => {
    const p = response.data?.data;
    return response.status === 200 && p && texto(p.propuesta) && p.propuesta.length <= 16384
      && typeof p.id_propuesta === 'string' && /^[a-f0-9-]{36}$/i.test(p.id_propuesta)
      && esIdentificador(p.paciente?.id_paciente) && texto(p.paciente.codigo_paciente) && texto(p.paciente.nombres) && texto(p.paciente.apellidos)
      && p.medico?.id_medico === payload.id_medico && texto(p.medico.nombres) && texto(p.medico.apellidos)
      && p.especialidad?.id_especialidad === payload.id_especialidad && texto(p.especialidad.nombre)
      && esIdentificador(p.consultorio?.id_consultorio) && texto(p.consultorio.codigo_consultorio) && texto(p.consultorio.nombre)
      && (payload.id_paciente === undefined || p.paciente.id_paciente === payload.id_paciente)
      && instante(p.inicio_at) && instante(p.fin_at) && p.zona_horaria === 'America/La_Paz' && p.modalidad === 'Presencial'
      && (datos.inicioEsperado === undefined || p.inicio_at === datos.inicioEsperado)
      && Number.isInteger(p.duracion_minutos) && p.duracion_minutos > 0 && texto(p.sede_atencion)
      && (p.direccion_sede === null || typeof p.direccion_sede === 'string') && p.motivo_breve === payload.motivo_breve;
  });
  return result.data;
}
export async function confirmarReserva({ propuesta, motivo_breve, pacienteEsperado }) {
  exigirPropuesta(propuesta);
  return postReserva('/citas', { propuesta, motivo_breve: normalizarMotivo(motivo_breve) }, response =>
    [200, 201].includes(response.status) && citaValida(response.data?.data)
    && (pacienteEsperado === undefined || response.data.data.id_paciente === pacienteEsperado)
    && typeof response.data?.meta?.repetida === 'boolean'
    && (response.status === 200 ? response.data.meta.repetida : !response.data.meta.repetida));
}
export async function consultarResultadoReserva({ propuesta, pacienteEsperado }) {
  exigirPropuesta(propuesta);
  const result = await postReserva('/reservas/resultado', { propuesta }, response => response.status === 200
    && typeof response.data?.data?.registrada === 'boolean'
    && (!response.data.data.registrada || (citaValida(response.data.data.cita)
      && (pacienteEsperado === undefined || response.data.data.cita.id_paciente === pacienteEsperado))));
  return result.data;
}
