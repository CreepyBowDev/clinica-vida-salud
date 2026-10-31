import { api, csrfURL } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarCita } from '../../agenda/services/agenda.service';
import { esFechaCivil, esInstanteCita, ESTADOS_CITA, ZONA_CLINICA } from '../../agenda/utils/agendaFormat';

const exigir = condition => { if (!condition) throw new Error('No se pudo verificar la gestión de la cita.'); };
const objeto = value => value && typeof value === 'object' && !Array.isArray(value);
const texto = value => typeof value === 'string' && value.trim().length > 0;
const impedimentosConocidos = ['CITA_GESTION_NO_PERMITIDA', 'CITA_PLAZO_GESTION_FINALIZADO', 'CITA_CON_ATENCION_DOCUMENTADA'];

export async function consultarGestionCita({ id }, signal) {
  exigir(esIdentificador(id));
  const response = await api.get(`/citas/${id}/gestion`, { signal, sessionBound: true });
  const d = response.data?.data;
  exigir(response.status === 200 && objeto(d) && d.id_cita === id);
  const result = {};
  for (const key of ['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio']) {
    exigir(esIdentificador(d[key])); result[key] = d[key];
  }
  for (const key of ['codigo_cita', 'sede_atencion', 'consultorio_atencion']) {
    exigir(texto(d[key])); result[key] = d[key];
  }
  for (const key of ['inicio_at', 'fin_at', 'actualizada_en', 'consultado_en']) {
    exigir(esInstanteCita(d[key])); result[key] = d[key];
  }
  exigir(d.inicio_at < d.fin_at && ESTADOS_CITA.includes(d.estado) && d.modalidad === 'Presencial'
    && Number.isInteger(d.duracion_minutos) && d.duracion_minutos > 0
    && Number.isInteger(d.version_programacion) && d.version_programacion > 0 && d.version_programacion <= 2147483647
    && d.zona_horaria === ZONA_CLINICA && objeto(d.acciones)
    && typeof d.acciones.reprogramar === 'boolean' && typeof d.acciones.cancelar === 'boolean'
    && Array.isArray(d.impedimentos) && d.impedimentos.every(code => impedimentosConocidos.includes(code))
    && new Set(d.impedimentos).size === d.impedimentos.length);
  // No recalcular elegibilidad con el reloj local ni con el estado profesional actual.
  exigir(d.acciones.reprogramar === (d.impedimentos.length === 0) && d.acciones.cancelar === (d.impedimentos.length === 0));
  let ultimo_cambio = null;
  if (d.ultimo_cambio !== null) {
    const c = d.ultimo_cambio;
    exigir(objeto(c) && esIdentificador(c.id_cambio_cita) && esIdentificador(c.id_actor) && texto(c.operacion) && esInstanteCita(c.realizado_en));
    ultimo_cambio = { id_cambio_cita: c.id_cambio_cita, id_actor: c.id_actor, operacion: c.operacion, realizado_en: c.realizado_en };
  }
  return { ...result, estado: d.estado, modalidad: d.modalidad, duracion_minutos: d.duracion_minutos,
    version_programacion: d.version_programacion, zona_horaria: d.zona_horaria,
    acciones: { reprogramar: d.acciones.reprogramar, cancelar: d.acciones.cancelar }, impedimentos: [...d.impedimentos], ultimo_cambio };
}

export async function consultarContextoGestion({ id, rol }, signal) {
  exigir(esIdentificador(id) && [ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA].includes(rol));
  const [gestion, detalle] = await Promise.all([consultarGestionCita({ id }, signal), consultarCita({ id, rol }, signal)]);
  exigir(gestion.id_paciente === detalle.paciente.id_paciente && gestion.id_medico === detalle.medico.id_medico
    && gestion.id_especialidad === detalle.especialidad.id_especialidad && gestion.codigo_cita === detalle.codigo_cita);
  // Solo etiquetas de identidad: nunca mezclar programación/acciones con otro GET ni retener CI/motivos/contactos.
  const persona = value => ({ nombres: value.nombres, apellidos: value.apellidos });
  return { gestion, paciente: persona(detalle.paciente), medico: persona(detalle.medico), especialidad: { nombre: detalle.especialidad.nombre } };
}

function programacion(d) {
  exigir(objeto(d));
  const result = {};
  for (const key of ['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio']) {
    exigir(esIdentificador(d[key])); result[key] = d[key];
  }
  for (const key of ['codigo_cita', 'sede_atencion', 'consultorio_atencion']) { exigir(texto(d[key])); result[key] = d[key]; }
  for (const key of ['inicio_at', 'fin_at', 'actualizada_en']) { exigir(esInstanteCita(d[key])); result[key] = d[key]; }
  exigir(d.inicio_at < d.fin_at && ESTADOS_CITA.includes(d.estado) && Number.isInteger(d.duracion_minutos) && d.duracion_minutos > 0
    && Number.isInteger(d.version_programacion) && d.version_programacion > 0 && d.version_programacion <= 2147483647);
  return { ...result, estado: d.estado, duracion_minutos: d.duracion_minutos, version_programacion: d.version_programacion };
}
function persona(value, tipo) {
  exigir(objeto(value) && esIdentificador(value[`id_${tipo}`]) && texto(value[`codigo_${tipo}`]) && texto(value.nombres) && texto(value.apellidos));
  return { [`id_${tipo}`]: value[`id_${tipo}`], [`codigo_${tipo}`]: value[`codigo_${tipo}`], nombres: value.nombres, apellidos: value.apellidos };
}
function horaLocal(instante) {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: ZONA_CLINICA, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(new Date(instante));
}
function fechaLocal(instante) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: ZONA_CLINICA, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(instante));
  return ['year', 'month', 'day'].map(key => parts.find(p => p.type === key).value.padStart(key === 'year' ? 4 : 2, '0')).join('-');
}
export async function consultarDisponibilidadReprogramacion({ id, fecha, page = 1, per_page = 12, original }, signal) {
  exigir(esIdentificador(id) && esFechaCivil(fecha) && Number.isInteger(page) && page >= 1 && page <= 1000000
    && Number.isInteger(per_page) && per_page >= 1 && per_page <= 100);
  const anterior = programacion(original); exigir(anterior.id_cita === id);
  const response = await api.get(`/citas/${id}/disponibilidad-reprogramacion`, { params: { fecha, page, per_page }, signal, sessionBound: true });
  const d = response.data?.data, meta = response.data?.meta;
  exigir(response.status === 200 && objeto(d));
  const cita_original = programacion(d.cita_original);
  // No adoptar silenciosamente otros originales tras una edición de la cita.
  exigir(Object.keys(anterior).every(key => anterior[key] === cita_original[key]));
  exigir(d.fecha === fecha && d.zona_horaria === ZONA_CLINICA && esInstanteCita(d.consultado_en)
    && Number.isInteger(d.duracion_minutos) && d.duracion_minutos > 0 && texto(d.sede_atencion)
    && (d.direccion_sede === null || typeof d.direccion_sede === 'string'));
  const paciente = persona(d.paciente, 'paciente'), medico = persona(d.medico, 'medico');
  exigir(paciente.id_paciente === anterior.id_paciente && medico.id_medico === anterior.id_medico
    && objeto(d.especialidad) && d.especialidad.id_especialidad === anterior.id_especialidad && texto(d.especialidad.nombre)
    && objeto(d.consultorio) && esIdentificador(d.consultorio.id_consultorio) && texto(d.consultorio.codigo_consultorio) && texto(d.consultorio.nombre));
  exigir(objeto(meta) && meta.current_page === page && meta.per_page === per_page && Number.isSafeInteger(meta.total) && meta.total >= 0
    && meta.last_page === Math.max(1, Math.ceil(meta.total / per_page)) && Array.isArray(d.turnos)
    && d.turnos.length === Math.min(per_page, Math.max(0, meta.total - (page - 1) * per_page)));
  const turnos = d.turnos.map(slot => {
    exigir(objeto(slot) && esInstanteCita(slot.inicio_at) && esInstanteCita(slot.fin_at) && slot.inicio_at < slot.fin_at
      && slot.inicio_at !== anterior.inicio_at && fechaLocal(slot.inicio_at) === fecha && horaLocal(slot.inicio_at) === slot.hora_inicio
      && Date.parse(slot.fin_at) - Date.parse(slot.inicio_at) === d.duracion_minutos * 60000
      && typeof slot.revision_turno === 'string' && /^[a-f0-9]{64}$/.test(slot.revision_turno));
    return { inicio_at: slot.inicio_at, fin_at: slot.fin_at, hora_inicio: slot.hora_inicio, revision_turno: slot.revision_turno };
  });
  exigir(turnos.every((slot, i) => i === 0 || turnos[i - 1].inicio_at < slot.inicio_at));
  return { data: { fecha, zona_horaria: ZONA_CLINICA, consultado_en: d.consultado_en, cita_original, paciente, medico,
    especialidad: { id_especialidad: d.especialidad.id_especialidad, nombre: d.especialidad.nombre },
    consultorio: { id_consultorio: d.consultorio.id_consultorio, codigo_consultorio: d.consultorio.codigo_consultorio, nombre: d.consultorio.nombre },
    sede_atencion: d.sede_atencion, direccion_sede: d.direccion_sede, duracion_minutos: d.duracion_minutos, turnos },
  meta: { current_page: page, per_page, last_page: meta.last_page, total: meta.total } };
}

async function escribirGestion(path, payload, verificar, signal) {
  try {
    signal?.throwIfAborted();
    await api.get(csrfURL, { sessionBound: true, signal });
    signal?.throwIfAborted();
  }
  catch (error) { error.gestionEnviada = false; throw error; }
  try {
    // Cancelar la espera del navegador no demuestra rollback si el PATCH ya salió.
    const response = await api.patch(path, payload, { sessionBound: true, signal });
    return verificar(response);
  } catch (error) { error.gestionEnviada = true; throw error; }
}

export async function reprogramarCita({ id, vista, turno }, signal) {
  // Todo lo que se confirma procede de una misma revisión; solo una lista cerrada viaja al servidor.
  exigir(esIdentificador(id) && objeto(vista) && objeto(turno));
  const original = programacion(vista.cita_original);
  exigir(original.id_cita === id && original.version_programacion < 2147483647 && esFechaCivil(vista.fecha)
    && ['Programada', 'Confirmada'].includes(original.estado) && Number.isInteger(vista.duracion_minutos) && vista.duracion_minutos > 0
    && objeto(vista.consultorio) && esIdentificador(vista.consultorio.id_consultorio) && texto(vista.consultorio.codigo_consultorio)
    && texto(vista.consultorio.nombre) && texto(vista.sede_atencion)
    && Array.isArray(vista.turnos) && vista.turnos.some(slot => slot.inicio_at === turno.inicio_at && slot.fin_at === turno.fin_at
      && slot.hora_inicio === turno.hora_inicio && slot.revision_turno === turno.revision_turno)
    && turno.inicio_at !== original.inicio_at && esInstanteCita(turno.inicio_at) && esInstanteCita(turno.fin_at)
    && turno.inicio_at < turno.fin_at && Date.parse(turno.fin_at) - Date.parse(turno.inicio_at) === vista.duracion_minutos * 60000
    && typeof turno.revision_turno === 'string' && /^[a-f0-9]{64}$/.test(turno.revision_turno)
    && fechaLocal(turno.inicio_at) === vista.fecha && horaLocal(turno.inicio_at) === turno.hora_inicio);
  const payload = { fecha: vista.fecha, hora_inicio: turno.hora_inicio, revision_turno: turno.revision_turno,
    version_programacion_original: original.version_programacion, actualizada_en_original: original.actualizada_en };
  return escribirGestion(`/citas/${id}/reprogramacion`, payload, response => {
    const d = response.data?.data, result = programacion(d), cambio = d.ultimo_cambio;
    exigir(response.status === 200 && ['id_cita', 'codigo_cita', 'id_paciente', 'id_medico', 'id_especialidad'].every(key => result[key] === original[key])
      && d.modalidad === 'Presencial' && result.estado === 'Programada' && result.inicio_at === turno.inicio_at && result.fin_at === turno.fin_at
      && result.duracion_minutos === vista.duracion_minutos && result.id_consultorio === vista.consultorio.id_consultorio
      && result.sede_atencion === vista.sede_atencion && result.consultorio_atencion === `${vista.consultorio.codigo_consultorio} — ${vista.consultorio.nombre}`
      && result.version_programacion === original.version_programacion + 1 && result.actualizada_en > original.actualizada_en
      && objeto(cambio) && esIdentificador(cambio.id_cambio_cita) && esIdentificador(cambio.id_actor)
      && cambio.operacion === 'REPROGRAMACION' && esInstanteCita(cambio.realizado_en));
    return { ...result, modalidad: d.modalidad, ultimo_cambio: { id_cambio_cita: cambio.id_cambio_cita, id_actor: cambio.id_actor,
      operacion: cambio.operacion, realizado_en: cambio.realizado_en } };
  }, signal);
}

export function normalizarMotivoCancelacion(value) {
  exigir(value === undefined || value === null || typeof value === 'string');
  const motivo = value?.trim() ?? '';
  exigir([...motivo].length <= 500 && !/\p{C}/u.test(motivo));
  return motivo || null;
}

export async function cancelarCita({ id, original: anterior, motivo }, signal) {
  exigir(esIdentificador(id));
  const original = programacion(anterior);
  exigir(original.id_cita === id && ['Programada', 'Confirmada'].includes(original.estado));
  const payload = { version_programacion_original: original.version_programacion, actualizada_en_original: original.actualizada_en,
    motivo: normalizarMotivoCancelacion(motivo) };
  return escribirGestion(`/citas/${id}/cancelacion`, payload, response => {
    const d = response.data?.data, result = programacion(d), cambio = d.ultimo_cambio;
    // Cancelar conserva toda la programación, incluida versión, duración y ubicación históricas.
    exigir(response.status === 200 && Object.keys(original).filter(key => !['estado', 'actualizada_en'].includes(key)).every(key => result[key] === original[key])
      && d.modalidad === 'Presencial' && result.estado === 'Cancelada' && result.actualizada_en > original.actualizada_en
      && objeto(cambio) && esIdentificador(cambio.id_cambio_cita) && esIdentificador(cambio.id_actor)
      && cambio.operacion === 'CANCELACION' && esInstanteCita(cambio.realizado_en));
    return { ...result, modalidad: d.modalidad, ultimo_cambio: { id_cambio_cita: cambio.id_cambio_cita, id_actor: cambio.id_actor,
      operacion: cambio.operacion, realizado_en: cambio.realizado_en } };
  }, signal);
}
