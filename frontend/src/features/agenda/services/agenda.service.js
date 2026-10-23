import { api } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esFechaCivil, esInstanteCita, ESTADOS_CITA, PERIODOS_CITA, ZONA_CLINICA } from '../utils/agendaFormat';

const fallo = () => { throw new Error('No se pudo verificar la consulta de citas.'); };
const texto = value => typeof value === 'string' && value.trim().length > 0;
const objeto = value => value && typeof value === 'object' && !Array.isArray(value);
function exigir(condition) { if (!condition) fallo(); }
function id(value) { exigir(esIdentificador(value)); return value; }
function entero(value, max) { exigir(['string', 'number'].includes(typeof value) && Number.isInteger(Number(value)) && /^(0|[1-9]\d*)$/.test(String(value)) && Number(value) >= 1 && Number(value) <= max); return Number(value); }
function paginacion({ page = 1, per_page = 20 }) { return { page: entero(page, 1000000), per_page: entero(per_page, 100) }; }
function opcional(params, filtros, campo, validar) {
  const value = filtros[campo];
  if (value === undefined || value === '') return;
  exigir(validar(value)); params[campo] = value;
}
function persona(value, tipo) {
  exigir(objeto(value));
  const clave = `id_${tipo}`, codigo = `codigo_${tipo}`;
  exigir(texto(value[codigo]) && texto(value.nombres) && texto(value.apellidos));
  return { [clave]: id(value[clave]), [codigo]: value[codigo], nombres: value.nombres, apellidos: value.apellidos };
}
function cita(value) {
  exigir(objeto(value) && texto(value.codigo_cita) && esInstanteCita(value.inicio_at) && esInstanteCita(value.fin_at)
    && value.inicio_at < value.fin_at && esInstanteCita(value.creada_en) && Number.isInteger(value.duracion_minutos) && value.duracion_minutos > 0
    && ESTADOS_CITA.includes(value.estado) && value.modalidad === 'Presencial' && texto(value.sede_atencion) && texto(value.consultorio_atencion)
    && objeto(value.especialidad) && texto(value.especialidad.nombre));
  return { id_cita: id(value.id_cita), codigo_cita: value.codigo_cita, inicio_at: value.inicio_at, fin_at: value.fin_at,
    duracion_minutos: value.duracion_minutos, estado: value.estado, modalidad: value.modalidad, sede_atencion: value.sede_atencion,
    consultorio_atencion: value.consultorio_atencion, creada_en: value.creada_en, medico: persona(value.medico, 'medico'),
    paciente: persona(value.paciente, 'paciente'), especialidad: { id_especialidad: id(value.especialidad.id_especialidad), nombre: value.especialidad.nombre } };
}
function pagina(value, params, proyectar) {
  const meta = value?.meta;
  exigir(Array.isArray(value?.data) && objeto(meta) && Number.isInteger(meta.total) && meta.total >= 0
    && meta.current_page === params.page && meta.per_page === params.per_page && Number.isInteger(meta.last_page) && meta.last_page >= 1
    && meta.last_page === Math.max(1, Math.ceil(meta.total / meta.per_page)) && value.data.length <= params.per_page);
  const data = value.data.map(proyectar);
  exigir(data.length === Math.min(params.per_page, Math.max(0, meta.total - (params.page - 1) * params.per_page)));
  const links = {};
  for (const name of ['first', 'last', 'prev', 'next']) {
    exigir(value.links?.[name] === null || typeof value.links?.[name] === 'string');
    links[name] = value.links[name];
  }
  return { data, links, meta: { current_page: meta.current_page, last_page: meta.last_page, per_page: meta.per_page, total: meta.total,
    from: data.length ? (params.page - 1) * params.per_page + 1 : null, to: data.length ? (params.page - 1) * params.per_page + data.length : null } };
}
async function get(path, params, signal) {
  return (await api.get(path, { ...(params ? { params } : {}), signal, sessionBound: true })).data;
}

export async function listarMedicosAgenda(filtros = {}, signal) {
  const params = paginacion(filtros);
  if (filtros.nombre !== undefined && filtros.nombre !== '') {
    exigir(typeof filtros.nombre === 'string');
    const nombre = filtros.nombre.trim();
    if (nombre) { exigir([...nombre].length <= 120 && !/\p{C}/u.test(nombre)); params.nombre = nombre; }
  }
  return pagina(await get('/agenda/medicos', params, signal), params, value => {
    exigir(typeof value?.activo === 'boolean');
    return { ...persona(value, 'medico'), activo: value.activo };
  });
}

export async function consultarAgenda(filtros = {}, signal) {
  const params = paginacion(filtros);
  if (filtros.id_medico !== undefined) params.id_medico = id(filtros.id_medico);
  opcional(params, filtros, 'fecha', esFechaCivil);
  opcional(params, filtros, 'estado', value => ESTADOS_CITA.includes(value));
  const value = await get('/agenda', params, signal);
  const result = pagina(value, params, cita);
  const meta = value.meta, medico = persona(meta.medico, 'medico');
  exigir(meta.zona_horaria === ZONA_CLINICA && esInstanteCita(meta.consultado_en) && esFechaCivil(meta.filtros?.fecha)
    && (params.fecha === undefined || meta.filtros.fecha === params.fecha) && meta.filtros.id_medico === medico.id_medico
    && (params.id_medico === undefined || medico.id_medico === params.id_medico)
    && meta.filtros.estado === (params.estado ?? null) && result.data.every(row => row.medico.id_medico === medico.id_medico));
  result.meta = { ...result.meta, zona_horaria: ZONA_CLINICA, consultado_en: meta.consultado_en, medico,
    filtros: { id_medico: medico.id_medico, fecha: meta.filtros.fecha, estado: meta.filtros.estado } };
  return result;
}

async function historial(path, filtros, signal, pacienteEsperado, periodoInicial) {
  const params = paginacion(filtros);
  opcional(params, filtros, 'periodo', value => PERIODOS_CITA.includes(value));
  opcional(params, filtros, 'estado', value => ESTADOS_CITA.includes(value));
  const value = await get(path, params, signal), result = pagina(value, params, cita);
  const meta = value.meta, paciente = persona(meta.paciente, 'paciente');
  exigir(meta.zona_horaria === ZONA_CLINICA && esInstanteCita(meta.consultado_en)
    && meta.filtros?.periodo === (params.periodo ?? periodoInicial) && meta.filtros.estado === (params.estado ?? null)
    && (pacienteEsperado === undefined || paciente.id_paciente === pacienteEsperado)
    && result.data.every(row => row.paciente.id_paciente === paciente.id_paciente));
  result.meta = { ...result.meta, zona_horaria: ZONA_CLINICA, consultado_en: meta.consultado_en, paciente,
    filtros: { periodo: meta.filtros.periodo, estado: meta.filtros.estado } };
  return result;
}

export function consultarMisCitas(filtros = {}, signal) {
  return historial('/mis-citas', filtros, signal, undefined, 'proximas');
}

export async function consultarHistorialPaciente({ id: paciente, ...filtros }, signal) {
  return historial(`/pacientes/${id(paciente)}/citas`, filtros, signal, paciente, 'todas');
}

export async function consultarCita({ id: objetivo, rol }, signal) {
  id(objetivo); exigir(Object.values(ROLES).includes(rol));
  const value = (await get(`/citas/${objetivo}`, null, signal))?.data;
  const result = cita(value);
  exigir(result.id_cita === objetivo);
  // El rol solo limita presentación. Laravel decide sesión, titularidad y campos autorizados.
  // Se proyectan claves explícitas: nunca conservar extras clínicos o administrativos de otra matriz.
  if (rol !== ROLES.ADMINISTRADOR) {
    exigir(value.motivo_breve === null || (typeof value.motivo_breve === 'string' && [...value.motivo_breve].length <= 500));
    result.motivo_breve = value.motivo_breve;
  }
  if (rol === ROLES.RECEPCIONISTA) {
    exigir((value.paciente.documento_identidad === null || texto(value.paciente.documento_identidad)) && texto(value.paciente.telefono));
    Object.assign(result.paciente, { documento_identidad: value.paciente.documento_identidad, telefono: value.paciente.telefono });
  }
  if (rol === ROLES.MEDICO) {
    exigir(value.paciente.fecha_nacimiento === null || esFechaCivil(value.paciente.fecha_nacimiento));
    result.paciente.fecha_nacimiento = value.paciente.fecha_nacimiento;
  }
  return result;
}
