import { api } from '../../../api/api';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esInstanteCita, ESTADOS_CITA, ZONA_CLINICA } from '../../agenda/utils/agendaFormat';
import { esPeriodoReporte } from '../utils/periodoReporte';

function exigir(condition) { if (!condition) throw new Error('No se pudo verificar el reporte.'); }
function entero(value, max) {
  exigir(['string', 'number'].includes(typeof value) && /^[1-9]\d*$/.test(String(value)) && Number.isSafeInteger(Number(value)) && Number(value) <= max);
  return Number(value);
}
function persona(value, tipo) {
  exigir(esIdentificador(value?.[`id_${tipo}`]) && ['nombres', 'apellidos', `codigo_${tipo}`].every(key => typeof value[key] === 'string'));
  return { [`id_${tipo}`]: value[`id_${tipo}`], [`codigo_${tipo}`]: value[`codigo_${tipo}`], nombres: value.nombres, apellidos: value.apellidos };
}
function cita(value) {
  exigir(esIdentificador(value?.id_cita) && ESTADOS_CITA.includes(value.estado) && esInstanteCita(value.inicio_at) && esInstanteCita(value.fin_at)
    && value.inicio_at < value.fin_at && esInstanteCita(value.creada_en) && Number.isInteger(value.duracion_minutos) && value.duracion_minutos > 0
    && value.modalidad === 'Presencial' && ['codigo_cita', 'sede_atencion', 'consultorio_atencion'].every(key => typeof value[key] === 'string')
    && esIdentificador(value.especialidad?.id_especialidad) && typeof value.especialidad.nombre === 'string');
  return { id_cita: value.id_cita, codigo_cita: value.codigo_cita, inicio_at: value.inicio_at, fin_at: value.fin_at,
    creada_en: value.creada_en, duracion_minutos: value.duracion_minutos, estado: value.estado, modalidad: value.modalidad,
    sede_atencion: value.sede_atencion, consultorio_atencion: value.consultorio_atencion, medico: persona(value.medico, 'medico'),
    paciente: persona(value.paciente, 'paciente'), especialidad: { id_especialidad: value.especialidad.id_especialidad, nombre: value.especialidad.nombre } };
}

async function consultarReporteCitas(recurso, filtros, signal) {
  const programadas = recurso === 'programadas';
  const permitidos = ['fecha', 'id_medico', 'id_especialidad', 'orden', 'page', 'per_page', ...(programadas ? [] : ['desde', 'hasta', 'estado'])];
  exigir(Object.keys(filtros).every(key => permitidos.includes(key)) && esPeriodoReporte(filtros) && (!programadas || filtros.fecha !== undefined));
  const params = { ...(filtros.fecha !== undefined ? { fecha: filtros.fecha } : { desde: filtros.desde, hasta: filtros.hasta }),
    orden: filtros.orden ?? 'fecha_asc', page: entero(filtros.page ?? 1, 1000000), per_page: entero(filtros.per_page ?? 20, 100) };
  exigir(['fecha_asc', 'fecha_desc'].includes(params.orden));
  for (const key of ['id_medico', 'id_especialidad']) {
    if (filtros[key] !== undefined && filtros[key] !== '') { exigir(esIdentificador(filtros[key])); params[key] = filtros[key]; }
  }
  if (!programadas && filtros.estado !== undefined && filtros.estado !== '') { exigir(ESTADOS_CITA.includes(filtros.estado)); params.estado = filtros.estado; }
  if (recurso === 'por-medico') exigir(params.id_medico !== undefined);
  if (recurso === 'por-especialidad') exigir(params.id_especialidad !== undefined);
  if (recurso === 'por-estado') exigir(params.estado !== undefined);
  const estado = programadas ? 'Programada' : (params.estado ?? null);
  const { data: value } = await api.get(`/reportes/citas/${recurso}`, { params, signal, sessionBound: true });
  const meta = value?.meta, context = meta?.filtros;
  exigir(Array.isArray(value?.data) && meta?.reporte === `citas/${recurso}` && meta.zona_horaria === ZONA_CLINICA && esInstanteCita(meta.consultado_en)
    && context?.fecha === (params.fecha ?? null) && context.desde === (params.desde ?? null) && context.hasta === (params.hasta ?? null)
    && context.estado === estado && context.orden === params.orden
    && context.id_medico === (params.id_medico ?? null) && context.id_especialidad === (params.id_especialidad ?? null)
    && meta.current_page === params.page && meta.per_page === params.per_page && Number.isSafeInteger(meta.total) && meta.total >= 0
    && meta.last_page === Math.max(1, Math.ceil(meta.total / params.per_page))
    && value.data.length === Math.min(params.per_page, Math.max(0, meta.total - (params.page - 1) * params.per_page)));
  const data = value.data.map(cita);
  exigir(data.every(row => (!params.id_medico || row.medico.id_medico === params.id_medico) && (!params.id_especialidad || row.especialidad.id_especialidad === params.id_especialidad)
    && (!estado || row.estado === estado)));
  return { data, meta: { current_page: meta.current_page, per_page: meta.per_page, last_page: meta.last_page, total: meta.total,
    consultado_en: meta.consultado_en, zona_horaria: ZONA_CLINICA, filtros: { fecha: context.fecha, desde: context.desde, hasta: context.hasta, estado: context.estado,
      orden: context.orden, id_medico: context.id_medico, id_especialidad: context.id_especialidad } } };
}

export function consultarCitasProgramadas(filtros = {}, signal) { return consultarReporteCitas('programadas', filtros, signal); }
export function consultarCitasPorMedico(filtros = {}, signal) { return consultarReporteCitas('por-medico', filtros, signal); }
export function consultarCitasPorEspecialidad(filtros = {}, signal) { return consultarReporteCitas('por-especialidad', filtros, signal); }
export function consultarCitasPorEstado(filtros = {}, signal) { return consultarReporteCitas('por-estado', filtros, signal); }

export async function consultarPacientesRegistrados(filtros = {}, signal) {
  exigir(Object.keys(filtros).every(key => ['fecha', 'desde', 'hasta', 'activo', 'orden', 'page', 'per_page'].includes(key)) && esPeriodoReporte(filtros));
  const params = { ...(filtros.fecha !== undefined ? { fecha: filtros.fecha } : { desde: filtros.desde, hasta: filtros.hasta }),
    orden: filtros.orden ?? 'registro_asc', page: entero(filtros.page ?? 1, 1000000), per_page: entero(filtros.per_page ?? 20, 100) };
  exigir(['registro_asc', 'registro_desc', 'nombre_asc'].includes(params.orden));
  if (filtros.activo !== undefined && filtros.activo !== '') {
    exigir(['0', '1', 0, 1].includes(filtros.activo)); params.activo = String(filtros.activo);
  }
  const activo = params.activo === undefined ? null : params.activo === '1';
  const { data: value } = await api.get('/reportes/pacientes/registrados', { params, signal, sessionBound: true });
  const meta = value?.meta, context = meta?.filtros;
  exigir(Array.isArray(value?.data) && meta?.reporte === 'pacientes/registrados' && meta.zona_horaria === ZONA_CLINICA && esInstanteCita(meta.consultado_en)
    && context?.fecha === (params.fecha ?? null) && context.desde === (params.desde ?? null) && context.hasta === (params.hasta ?? null)
    && context.activo === activo && context.orden === params.orden
    && meta.current_page === params.page && meta.per_page === params.per_page && Number.isSafeInteger(meta.total) && meta.total >= 0
    && meta.last_page === Math.max(1, Math.ceil(meta.total / params.per_page))
    && value.data.length === Math.min(params.per_page, Math.max(0, meta.total - (params.page - 1) * params.per_page)));
  const data = value.data.map(row => {
    exigir(esIdentificador(row?.id_paciente) && ['codigo_paciente', 'nombres', 'apellidos'].every(key => typeof row[key] === 'string')
      && typeof row.activo === 'boolean' && (activo === null || row.activo === activo) && esInstanteCita(row.creado_en));
    return { id_paciente: row.id_paciente, codigo_paciente: row.codigo_paciente, nombres: row.nombres, apellidos: row.apellidos,
      activo: row.activo, creado_en: row.creado_en };
  });
  return { data, meta: { current_page: meta.current_page, per_page: meta.per_page, last_page: meta.last_page, total: meta.total,
    consultado_en: meta.consultado_en, zona_horaria: ZONA_CLINICA,
    filtros: { fecha: context.fecha, desde: context.desde, hasta: context.hasta, activo: context.activo, orden: context.orden } } };
}

export async function consultarCantidadesCitas(filtros = {}, signal) {
  exigir(Object.keys(filtros).every(key => ['fecha', 'desde', 'hasta', 'id_medico', 'id_especialidad'].includes(key)) && esPeriodoReporte(filtros));
  const params = filtros.fecha !== undefined ? { fecha: filtros.fecha } : { desde: filtros.desde, hasta: filtros.hasta };
  for (const key of ['id_medico', 'id_especialidad']) {
    if (filtros[key] !== undefined && filtros[key] !== '') { exigir(esIdentificador(filtros[key])); params[key] = filtros[key]; }
  }
  const { data: value } = await api.get('/reportes/citas/cantidades', { params, signal, sessionBound: true });
  const meta = value?.meta, context = meta?.filtros, counts = value?.data;
  exigir(counts && !Array.isArray(counts) && ['atendidas', 'canceladas', 'inasistencias', 'total'].every(key => Number.isSafeInteger(counts[key]) && counts[key] >= 0)
    && meta?.reporte === 'citas/cantidades' && meta.zona_horaria === ZONA_CLINICA && esInstanteCita(meta.consultado_en)
    && context?.fecha === (params.fecha ?? null) && context.desde === (params.desde ?? null) && context.hasta === (params.hasta ?? null)
    && context.id_medico === (params.id_medico ?? null) && context.id_especialidad === (params.id_especialidad ?? null)
    && Array.isArray(context.estados) && context.estados.join('|') === 'Atendida|Cancelada|Inasistencia'
    && meta.periodo?.referencia === 'citas.inicio_at' && meta.periodo.criterio === 'inicio_en_periodo');
  return { data: { atendidas: counts.atendidas, canceladas: counts.canceladas, inasistencias: counts.inasistencias, total: counts.total },
    meta: { consultado_en: meta.consultado_en, zona_horaria: ZONA_CLINICA, filtros: { fecha: context.fecha, desde: context.desde, hasta: context.hasta,
      id_medico: context.id_medico, id_especialidad: context.id_especialidad, estados: ['Atendida', 'Cancelada', 'Inasistencia'] } } };
}

export async function consultarAgendaDiaria(filtros = {}, signal) {
  exigir(Object.keys(filtros).every(key => ['fecha', 'id_medico', 'estado', 'page', 'per_page'].includes(key)) && esPeriodoReporte(filtros)
    && filtros.fecha !== undefined && esIdentificador(filtros.id_medico));
  const params = { fecha: filtros.fecha, id_medico: filtros.id_medico, page: entero(filtros.page ?? 1, 1000000), per_page: entero(filtros.per_page ?? 20, 100) };
  if (filtros.estado !== undefined && filtros.estado !== '') { exigir(ESTADOS_CITA.includes(filtros.estado)); params.estado = filtros.estado; }
  const { data: value } = await api.get('/reportes/agenda-diaria', { params, signal, sessionBound: true });
  const meta = value?.meta, context = meta?.filtros;
  exigir(Array.isArray(value?.data) && meta?.reporte === 'agenda-diaria' && meta.zona_horaria === ZONA_CLINICA && esInstanteCita(meta.consultado_en)
    && context?.fecha === params.fecha && context.id_medico === params.id_medico && context.estado === (params.estado ?? null)
    && meta.periodo?.referencia === 'citas.inicio_at' && meta.periodo.criterio === 'interseccion_diaria'
    && meta.current_page === params.page && meta.per_page === params.per_page && Number.isSafeInteger(meta.total) && meta.total >= 0
    && meta.last_page === Math.max(1, Math.ceil(meta.total / params.per_page))
    && value.data.length === Math.min(params.per_page, Math.max(0, meta.total - (params.page - 1) * params.per_page)));
  const medico = persona(meta.medico, 'medico'), data = value.data.map(cita);
  exigir(medico.id_medico === params.id_medico && data.every(row => row.medico.id_medico === params.id_medico && (!params.estado || row.estado === params.estado)));
  return { data, meta: { current_page: meta.current_page, per_page: meta.per_page, last_page: meta.last_page, total: meta.total,
    consultado_en: meta.consultado_en, zona_horaria: ZONA_CLINICA, medico,
    filtros: { fecha: context.fecha, id_medico: context.id_medico, estado: context.estado } } };
}
