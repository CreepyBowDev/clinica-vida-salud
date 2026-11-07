import { api, csrfURL, obtenerEpocaSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esFechaCivil, esInstanteCita, ZONA_CLINICA } from '../../agenda/utils/agendaFormat';

const objeto = v => v !== null && typeof v === 'object' && !Array.isArray(v);
const texto = v => typeof v === 'string' && v.trim().length > 0;
function exigir(value) { if (!value) throw new Error('No se pudo verificar la consulta de recordatorios.'); }
function resultadoManual(v) {
  exigir(objeto(v) && esIdentificador(v.id_recordatorio) && esIdentificador(v.id_cita)
    && v.aviso_interno === 'Generado' && ['Pendiente', 'Sin_dispositivos', 'No_disponible'].includes(v.push));
  return { id_recordatorio: v.id_recordatorio, id_cita: v.id_cita, aviso_interno: v.aviso_interno, push: v.push };
}
function comprobarContexto(options) {
  if (options.signal?.aborted || options.sessionEpochExpected !== obtenerEpocaSesion()) {
    throw Object.assign(new Error('El contexto de sesión cambió.'), { code: 'SESSION_SUPERSEDED' });
  }
}

export async function consultarProximaCita(idPaciente, operacion, signal) {
  exigir(esIdentificador(idPaciente));
  const options = { signal, sessionBound: true, sessionEpochExpected: obtenerEpocaSesion() };
  const response = await api.get('/recordatorios/proxima-cita', { ...options,
    params: { id_paciente: idPaciente, ...(operacion ? { operacion } : {}) } });
  comprobarContexto(options);
  const data = response.data?.data, c = data?.cita;
  exigir(response.status === 200 && objeto(data) && (c === null || (objeto(c) && esIdentificador(c.id_cita)
    && /^[a-f0-9]{64}$/.test(c.revision) && esInstanteCita(c.inicio_at) && texto(c.medico) && texto(c.consultorio)
    && typeof c.manual_generado === 'boolean' && typeof c.preferencia_habilitada === 'boolean')));
  return { cita: c === null ? null : { id_cita: c.id_cita, revision: c.revision, inicio_at: c.inicio_at,
    medico: c.medico, consultorio: c.consultorio, manual_generado: c.manual_generado, preferencia_habilitada: c.preferencia_habilitada },
  operacion: data.operacion === null ? null : resultadoManual(data.operacion) };
}

export async function enviarRecordatorioManual(idPaciente, cita, operacion, signal) {
  exigir(esIdentificador(idPaciente) && esIdentificador(cita?.id_cita) && /^[a-f0-9]{64}$/.test(cita?.revision));
  const options = { signal, sessionBound: true, sessionEpochExpected: obtenerEpocaSesion() };
  try { comprobarContexto(options); await api.get(csrfURL, options); comprobarContexto(options); }
  catch (error) { error.recordatorioEnviado = false; throw error; }
  try {
    const response = await api.post('/recordatorios/manual', { id_paciente: idPaciente, id_cita: cita.id_cita,
      revision: cita.revision, operacion }, options);
    comprobarContexto(options);
    exigir(response.status === 200 && response.data?.data?.id_cita === cita.id_cita);
    return resultadoManual(response.data.data);
  } catch (error) { error.recordatorioEnviado = true; throw error; }
}
function proyectarAviso(v) {
  const c = v?.cita;
  exigir(objeto(v) && esIdentificador(v.id_recordatorio) && esIdentificador(v.id_cita)
    && Number.isInteger(v.version_programacion) && v.version_programacion > 0 && esInstanteCita(v.generado_en)
    && objeto(c) && texto(c.codigo_cita) && esInstanteCita(c.inicio_at) && esInstanteCita(c.fin_at)
    && ['Programada', 'Confirmada'].includes(c.estado) && c.modalidad === 'Presencial' && texto(c.sede_atencion)
    && esIdentificador(c.consultorio?.id_consultorio) && texto(c.consultorio.ubicacion)
    && esIdentificador(c.medico?.id_medico) && texto(c.medico.codigo_medico) && texto(c.medico.nombres) && texto(c.medico.apellidos)
    && esIdentificador(c.especialidad?.id_especialidad) && texto(c.especialidad.nombre));
  return { id_recordatorio: v.id_recordatorio, id_cita: v.id_cita, cita: {
    codigo_cita: c.codigo_cita, inicio_at: c.inicio_at, fin_at: c.fin_at, estado: c.estado, modalidad: c.modalidad,
    sede_atencion: c.sede_atencion, consultorio: { ubicacion: c.consultorio.ubicacion },
    medico: { nombres: c.medico.nombres, apellidos: c.medico.apellidos }, especialidad: { nombre: c.especialidad.nombre },
  } };
}

// Lectura propia o administrativa; el servidor conserva la autorización definitiva.
export async function listarRecordatorios({ rol, page = 1, per_page = 20, fecha_desde = '', fecha_hasta = '', ...extra }, signal) {
  const admin = rol === ROLES.ADMINISTRADOR;
  exigir(admin ? Object.keys(extra).length === 1 && esIdentificador(extra.id_paciente)
    : rol === ROLES.PACIENTE && Object.keys(extra).length === 0);
  exigir(Number.isInteger(page) && page >= 1 && page <= 2147483647 && Number.isInteger(per_page) && per_page >= 1 && per_page <= 100);
  const params = { page, per_page };
  if (admin) params.id_paciente = extra.id_paciente;
  for (const [k, v] of Object.entries({ fecha_desde, fecha_hasta })) if (v) { exigir(esFechaCivil(v)); params[k] = v; }
  exigir(!fecha_desde || !fecha_hasta || fecha_desde <= fecha_hasta);
  const epoca = obtenerEpocaSesion();
  const r = await api.get('/recordatorios', { params, signal, sessionBound: true, sessionEpochExpected: epoca });
  if (signal?.aborted || epoca !== obtenerEpocaSesion()) throw Object.assign(new Error('Consulta interrumpida.'), { code: 'SESSION_SUPERSEDED' });
  const rows = r.data?.data, m = r.data?.meta;
  exigir(r.status === 200 && Array.isArray(rows) && objeto(m) && m.zona_horaria === ZONA_CLINICA && esInstanteCita(m.consultado_en)
    && m.current_page === page && m.per_page === per_page && Number.isSafeInteger(m.total) && m.total >= 0
    && m.last_page === Math.max(1, Math.ceil(m.total / per_page))
    && rows.length === Math.min(per_page, Math.max(0, m.total - (page - 1) * per_page))
    && m.filtros?.fecha_desde === (fecha_desde || null) && m.filtros?.fecha_hasta === (fecha_hasta || null));
  const data = rows.map(proyectarAviso);
  if (admin) exigir(m.paciente?.id_paciente === extra.id_paciente);
  exigir(new Set(data.map(v => v.id_recordatorio)).size === data.length);
  return { data, meta: { current_page: m.current_page, per_page: m.per_page, total: m.total, last_page: m.last_page,
    zona_horaria: m.zona_horaria, consultado_en: m.consultado_en } };
}
