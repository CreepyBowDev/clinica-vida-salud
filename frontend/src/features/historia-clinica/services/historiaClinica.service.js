import { api, obtenerEpocaSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarMiPerfil } from '../../pacientes/services/perfil.service';
import { esInstanteCita, ESTADOS_CITA, ZONA_CLINICA } from '../../agenda/utils/agendaFormat';

const camposConsulta = ['tipo_consulta', 'motivo_consulta', 'sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento'];
const camposAntecedentes = ['tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes'];
const internos = [ROLES.ADMINISTRADOR, ROLES.MEDICO];
function exigir(value) { if (!value) throw new Error('No se pudo verificar la información clínica.'); }
const objeto = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const texto = value => typeof value === 'string' && value.trim().length > 0;
function id(value) { exigir(esIdentificador(value)); return value; }
function rolValido(rol, interno = false) { exigir((interno ? internos : [...internos, ROLES.PACIENTE]).includes(rol)); }
function parametros({ page = 1, per_page = 20 }) {
  exigir(Number.isInteger(page) && page >= 1 && page <= 2147483647 && Number.isInteger(per_page) && per_page >= 1 && per_page <= 100);
  return { page, per_page };
}
function persona(value, tipo) {
  exigir(objeto(value) && texto(value[`codigo_${tipo}`]) && texto(value.nombres) && texto(value.apellidos));
  return { [`id_${tipo}`]: id(value[`id_${tipo}`]), [`codigo_${tipo}`]: value[`codigo_${tipo}`], nombres: value.nombres, apellidos: value.apellidos };
}
function version(value, tipo, rol) {
  exigir(objeto(value) && Number.isInteger(value.numero_version) && value.numero_version >= 1 && value.numero_version <= 2147483647 && esInstanteCita(value.registrada_en));
  const clave = tipo === 'consulta' ? 'id_version_consulta' : 'id_version_antecedente';
  const result = { [clave]: id(value[clave]), numero_version: value.numero_version, registrada_en: value.registrada_en };
  for (const campo of tipo === 'consulta' ? camposConsulta : camposAntecedentes) {
    const max = campo === 'tipo_sangre' ? 16 : campo === 'motivo_consulta' ? 4000 : 10000;
    exigir(value[campo] === null || (typeof value[campo] === 'string' && [...value[campo]].length <= max));
    result[campo] = value[campo];
  }
  if (tipo === 'consulta') exigir(['Control', 'Enfermedad'].includes(result.tipo_consulta) && texto(result.motivo_consulta));
  if (internos.includes(rol)) {
    result.id_autor = id(value.id_autor);
    exigir(value.motivo_cambio === null || (typeof value.motivo_cambio === 'string' && [...value.motivo_cambio].length <= 2000));
    result.motivo_cambio = value.motivo_cambio;
    if (tipo === 'antecedentes') result.id_consulta_origen = id(value.id_consulta_origen);
  }
  return result;
}
function pagina(value, params, rows) {
  const m = value?.meta;
  exigir(objeto(m) && m.zona_horaria === ZONA_CLINICA && m.current_page === params.page && m.per_page === params.per_page
    && Number.isSafeInteger(m.total) && m.total >= 0 && m.last_page === Math.max(1, Math.ceil(m.total / params.per_page))
    && Array.isArray(rows) && rows.length === Math.min(params.per_page, Math.max(0, m.total - (params.page - 1) * params.per_page)));
  return { current_page: m.current_page, per_page: m.per_page, total: m.total, last_page: m.last_page, zona_horaria: ZONA_CLINICA };
}
async function get(path, params, signal, sessionEpochExpected) {
  const response = await api.get(path, { params, signal, sessionBound: true, sessionEpochExpected });
  exigir(response.status === 200 && objeto(response.data));
  return response.data;
}

export async function consultarHistoria({ id: objetivo, rol, propia = false, ...filtros }, signal) {
  const epoca = obtenerEpocaSesion();
  rolValido(rol);
  const params = parametros(filtros);
  if (propia) {
    exigir(rol === ROLES.PACIENTE && objetivo === undefined);
    objetivo = id((await consultarMiPerfil(signal, epoca))?.id_paciente);
    if (signal?.aborted) throw new Error('Consulta interrumpida.');
  } else { exigir(rol !== ROLES.PACIENTE); id(objetivo); }
  const value = await get(`/pacientes/${objetivo}/historia-clinica`, params, signal, epoca);
  const d = value.data;
  exigir(objeto(d) && Array.isArray(d.consultas));
  const paciente = persona(d.paciente, 'paciente'); exigir(paciente.id_paciente === objetivo);
  const meta = pagina(value, params, d.consultas);
  const consultas = d.consultas.map(c => {
    exigir(objeto(c) && texto(c.codigo_consulta) && esInstanteCita(c.inicio_at) && ['Control', 'Enfermedad'].includes(c.tipo_consulta));
    return { id_consulta: id(c.id_consulta), codigo_consulta: c.codigo_consulta, id_cita: id(c.id_cita), inicio_at: c.inicio_at,
      tipo_consulta: c.tipo_consulta, medico: persona(c.medico, 'medico') };
  });
  exigir(new Set(consultas.map(c => c.id_consulta)).size === consultas.length);
  return { paciente, antecedentes: d.antecedentes === null ? null : version(d.antecedentes, 'antecedentes', rol), consultas, meta };
}

export async function consultarConsulta({ id: objetivo, rol, paraRegistro = false, ...filtros }, signal) {
  const epoca = obtenerEpocaSesion();
  rolValido(rol); id(objetivo);
  if (paraRegistro) rolValido(rol, true);
  const params = parametros(filtros), value = await get(`/citas/${objetivo}/consulta`, params, signal, epoca);
  const result = proyectarConsulta(value, { id: objetivo, rol, ...params }, paraRegistro);
  // Metadato local de transporte, no campo de Laravel ni dato que se envíe en el cuerpo.
  return paraRegistro ? { ...result, epocaSesion: epoca } : result;
}

export function proyectarConsulta(value, { id: objetivo, rol, ...filtros }, paraRegistro = false) {
  rolValido(rol); id(objetivo); if (paraRegistro) rolValido(rol, true);
  const params = parametros(filtros), d = value?.data;
  exigir(objeto(d) && objeto(d.cita) && d.cita.id_cita === objetivo && Array.isArray(d.estudios));
  const paciente = persona(d.paciente, 'paciente'), c = d.cita;
  exigir(c.id_paciente === paciente.id_paciente && c.id_medico === c.medico?.id_medico && texto(c.codigo_cita)
    && esInstanteCita(c.inicio_at) && esInstanteCita(c.fin_at) && c.inicio_at < c.fin_at && ESTADOS_CITA.includes(c.estado)
    && c.modalidad === 'Presencial' && texto(c.sede_atencion) && texto(c.consultorio_atencion));
  const cita = { id_cita: objetivo, codigo_cita: c.codigo_cita, inicio_at: c.inicio_at, fin_at: c.fin_at, estado: c.estado,
    sede_atencion: c.sede_atencion, consultorio_atencion: c.consultorio_atencion, medico: persona(c.medico, 'medico') };
  let consulta = null;
  if (d.consulta !== null) {
    const v = d.consulta;
    exigir(objeto(v) && texto(v.codigo_consulta) && esInstanteCita(v.creada_en) && (v.iniciada_en === null || esInstanteCita(v.iniciada_en)));
    consulta = { id_consulta: id(v.id_consulta), codigo_consulta: v.codigo_consulta, creada_en: v.creada_en, iniciada_en: v.iniciada_en,
      version_vigente: version(v.version_vigente, 'consulta', rol) };
  }
  const meta = pagina(value, params, d.estudios);
  exigir(consulta !== null || meta.total === 0);
  const estudios = d.estudios.map(e => {
    exigir(objeto(e) && texto(e.estudio_solicitado) && esInstanteCita(e.solicitada_en)
      && (rol === ROLES.PACIENTE ? ['Pendiente', 'Disponible'] : ['Pendiente', 'En revisión', 'Disponible']).includes(e.estado));
    let resultado = null;
    if (e.resultado !== null) {
      exigir(objeto(e.resultado) && Number.isInteger(e.resultado.numero_version) && e.resultado.numero_version >= 1 && esInstanteCita(e.resultado.publicado_en) && e.estado === 'Disponible');
      resultado = { numero_version: e.resultado.numero_version, publicado_en: e.resultado.publicado_en };
    }
    return { id_solicitud: id(e.id_solicitud), estudio_solicitado: e.estudio_solicitado, solicitada_en: e.solicitada_en, estado: e.estado, resultado };
  });
  exigir(new Set(estudios.map(e => e.id_solicitud)).size === estudios.length);
  const antecedentes = d.antecedentes === null ? null : version(d.antecedentes, 'antecedentes', rol);
  const result = { paciente, cita, consulta, antecedentes, estudios, meta };
  // La lectura ordinaria sigue descartando contexto editable, archivos y extras.
  if (paraRegistro) {
    result.edicion = null;
    if (d.edicion !== undefined) {
      const e = d.edicion, o = e?.originales;
      exigir(objeto(e) && objeto(o) && objeto(e.acciones) && objeto(e.impedimentos) && esInstanteCita(e.consultado_en)
        && Number.isInteger(o.version_programacion) && o.version_programacion >= 1 && o.version_programacion <= 2147483647
        && o.version_programacion === c.version_programacion && esInstanteCita(o.actualizada_en) && o.actualizada_en === c.actualizada_en
        && o.id_consulta === (consulta?.id_consulta ?? null) && o.id_version_consulta === (consulta?.version_vigente.id_version_consulta ?? null)
        && o.id_version_antecedente === (antecedentes?.id_version_antecedente ?? null));
      const acciones = {}, impedimentos = {};
      for (const action of ['registrar', 'actualizar']) {
        const codigos = ['CLINICA_EDICION_OBSOLETA', 'CLINICA_CITA_NO_PERMITIDA', 'CLINICA_ATENCION_ANTICIPADA', action === 'registrar' ? 'CLINICA_CONSULTA_EXISTENTE' : 'RECURSO_NO_ENCONTRADO'];
        const lista = e.impedimentos[action];
        exigir(typeof e.acciones[action] === 'boolean' && Array.isArray(lista) && new Set(lista).size === lista.length
          && lista.every(code => codigos.includes(code)) && e.acciones[action] === (lista.length === 0));
        acciones[action] = e.acciones[action]; impedimentos[action] = [...lista];
      }
      exigir(!acciones.registrar || consulta === null);
      exigir(!acciones.actualizar || consulta !== null);
      result.edicion = { originales: { version_programacion: o.version_programacion, actualizada_en: o.actualizada_en,
        id_consulta: o.id_consulta, id_version_consulta: o.id_version_consulta, id_version_antecedente: o.id_version_antecedente },
      consultado_en: e.consultado_en, acciones, impedimentos };
    }
  }
  return result;
}

export async function consultarVersiones({ id: objetivo, rol, tipo, ...filtros }, signal) {
  const epoca = obtenerEpocaSesion();
  rolValido(rol, true); id(objetivo); exigir(['consulta', 'antecedentes'].includes(tipo));
  const params = parametros(filtros);
  const path = tipo === 'consulta' ? `/citas/${objetivo}/consulta/versiones` : `/pacientes/${objetivo}/historia-clinica/antecedentes/versiones`;
  const value = await get(path, params, signal, epoca);
  exigir(Array.isArray(value.data));
  const versiones = value.data.map(v => version(v, tipo, rol));
  exigir(new Set(versiones.map(v => v.numero_version)).size === versiones.length);
  return { versiones, meta: pagina(value, params, value.data) };
}
