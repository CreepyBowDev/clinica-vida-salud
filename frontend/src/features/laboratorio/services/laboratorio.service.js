import { api, csrfURL, obtenerEpocaSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esFechaCivil, esInstanteCita, ZONA_CLINICA } from '../../agenda/utils/agendaFormat';
import { consultarConsulta } from '../../historia-clinica/services/historiaClinica.service';

export const MIME_RESULTADOS = Object.freeze({ 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/png': 'png' });
export const MAX_RESULTADO = 10485760;
const objeto = v => v !== null && typeof v === 'object' && !Array.isArray(v);
const texto = v => typeof v === 'string' && v.trim().length > 0;
function exigir(value) { if (!value) throw new Error('No se pudo verificar la información de laboratorio.'); }
function id(value) { exigir(esIdentificador(value)); return value; }
function numero(value) { exigir(Number.isInteger(value) && value > 0 && value <= 2147483647); return value; }
function rolValido(rol) { exigir([ROLES.ADMINISTRADOR, ROLES.MEDICO, ROLES.PACIENTE].includes(rol)); }
function persona(value, tipo) {
  exigir(objeto(value) && texto(value[`codigo_${tipo}`]) && texto(value.nombres) && texto(value.apellidos));
  return { [`id_${tipo}`]: id(value[`id_${tipo}`]), [`codigo_${tipo}`]: value[`codigo_${tipo}`], nombres: value.nombres, apellidos: value.apellidos };
}

// Lectura mínima por defecto; cada contexto de escritura se solicita explícitamente según su rol.
export function proyectarSolicitud(v, rol, detalle = false, paraDocumentar = false, paraRevisar = false) {
  rolValido(rol);
  if (paraDocumentar) exigir(detalle && rol === ROLES.ADMINISTRADOR);
  if (paraRevisar) exigir(detalle && [ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol));
  exigir(objeto(v) && texto(v.estudio_solicitado) && [...v.estudio_solicitado].length <= 255 && esInstanteCita(v.solicitada_en)
    && (rol === ROLES.PACIENTE ? ['Pendiente', 'Disponible'] : ['Pendiente', 'En revisión', 'Disponible']).includes(v.estado));
  const d = { id_solicitud: id(v.id_solicitud), id_consulta: id(v.id_consulta), estudio_solicitado: v.estudio_solicitado,
    solicitada_en: v.solicitada_en, paciente: persona(v.paciente, 'paciente'), medico: persona(v.medico, 'medico'), estado: v.estado, resultado: null };
  if (v.resultado !== null) {
    const r = v.resultado;
    exigir(objeto(r) && v.estado === 'Disponible' && esInstanteCita(r.publicado_en) && Object.hasOwn(MIME_RESULTADOS, r.tipo_mime)
      && Number.isInteger(r.tamano_bytes) && r.tamano_bytes > 0 && r.tamano_bytes <= MAX_RESULTADO);
    d.resultado = { id_version_resultado: id(r.id_version_resultado), numero_version: numero(r.numero_version), publicado_en: r.publicado_en,
      tipo_mime: r.tipo_mime, tamano_bytes: r.tamano_bytes };
  }
  exigir((v.estado === 'Disponible') === (d.resultado !== null));
  if (rol !== ROLES.PACIENTE) {
    d.creada_por = v.creada_por === null ? null : id(v.creada_por);
    if (detalle) {
      const e = v.edicion;
      exigir(objeto(e));
      d.documento = e.vigente === null ? null : { id_version_resultado: id(e.vigente?.id_version_resultado), numero_version: numero(e.vigente?.numero_version) };
      exigir(v.estado !== 'Pendiente' || d.documento === null);
      if (d.resultado) exigir(d.documento?.id_version_resultado === d.resultado.id_version_resultado && d.documento.numero_version === d.resultado.numero_version);
      const r = e.ultima_revision;
      d.ultima_revision = null;
      if (r !== null) {
        exigir(objeto(r) && ['Autorizar', 'Devolver'].includes(r.decision) && esInstanteCita(r.revisada_en)
          && (r.motivo === null || (texto(r.motivo) && [...r.motivo].length <= 2000)) && r.id_medico_responsable === d.medico.id_medico);
        exigir(d.documento !== null && (r.decision === 'Devolver' ? texto(r.motivo) && v.estado === 'En revisión' : v.estado === 'Disponible'));
        d.ultima_revision = { id_revision: id(r.id_revision), decision: r.decision, motivo: r.motivo, revisada_en: r.revisada_en,
          id_medico_responsable: id(r.id_medico_responsable), revisada_por: r.revisada_por === null ? null : id(r.revisada_por) };
      }
      exigir(v.estado !== 'Disponible' || d.ultima_revision?.decision === 'Autorizar');
      if (paraDocumentar || paraRevisar) {
        const o = e.originales, acciones = {};
        exigir(objeto(o) && o.estado_interno === d.estado && o.id_version_actual === (d.documento?.id_version_resultado ?? null)
          && o.id_ultima_revision === (d.ultima_revision?.id_revision ?? null) && Number.isInteger(o.ultima_version) && o.ultima_version >= 0 && o.ultima_version <= 2147483647
          && (!d.documento || d.documento.numero_version === o.ultima_version));
        const originales = { estado_interno: o.estado_interno, id_version_actual: o.id_version_actual, id_ultima_revision: o.id_ultima_revision, ultima_version: o.ultima_version };
        if (paraDocumentar) {
          for (const k of ['cargar', 'corregir', 'reasociar']) { exigir(typeof e.acciones?.[k] === 'boolean'); acciones[k] = e.acciones[k]; }
          exigir(!acciones.cargar || (d.estado !== 'Disponible' && d.documento === null));
          exigir(!(acciones.corregir || acciones.reasociar) || (d.estado === 'En revisión' && d.documento !== null && d.ultima_revision?.decision === 'Devolver'));
          d.edicion = { originales: { ...originales }, acciones };
        }
        if (paraRevisar) {
          exigir(typeof e.acciones?.revisar === 'boolean' && (!e.acciones.revisar || (d.estado === 'En revisión' && d.documento !== null && d.ultima_revision === null)));
          d.revision = { originales: { ...originales }, acciones: { revisar: e.acciones.revisar } };
        }
      }
    }
  }
  return d;
}

function filtros({ page = 1, per_page = 20, estado = '', fecha_desde = '', fecha_hasta = '' }, rol) {
  numero(page); exigir(Number.isInteger(per_page) && per_page >= 1 && per_page <= 100);
  const p = { page, per_page };
  if (estado) { exigir((rol === ROLES.PACIENTE ? ['Pendiente', 'Disponible'] : ['Pendiente', 'En revisión', 'Disponible']).includes(estado)); p.estado = estado; }
  for (const [k, v] of Object.entries({ fecha_desde, fecha_hasta })) if (v) { exigir(esFechaCivil(v)); p[k] = v; }
  exigir(!fecha_desde || !fecha_hasta || fecha_desde <= fecha_hasta);
  return p;
}
function vigente(epoca, signal) {
  if (epoca !== obtenerEpocaSesion() || signal?.aborted) throw Object.assign(new Error('Consulta interrumpida.'), { code: 'SESSION_SUPERSEDED' });
}
export async function listarSolicitudes({ rol, paciente, consulta, paraSolicitar = false, ...rest }, signal) {
  rolValido(rol); exigir(!(paciente && consulta));
  if (paraSolicitar) exigir(consulta && [ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol));
  exigir(!paciente || rol === ROLES.ADMINISTRADOR);
  exigir(rol !== ROLES.ADMINISTRADOR || paciente || consulta);
  const params = filtros(rest, rol), epoca = obtenerEpocaSesion();
  if (paciente) params.id_paciente = id(paciente);
  const path = consulta ? `/consultas/${id(consulta)}/solicitudes-laboratorio` : '/solicitudes-laboratorio';
  const r = await api.get(path, { params, signal, sessionBound: true, sessionEpochExpected: epoca });
  vigente(epoca, signal);
  exigir(r.status === 200 && objeto(r.data));
  if (consulta) exigir(r.data.data?.id_consulta === consulta);
  const rows = consulta ? r.data.data.solicitudes : r.data.data, m = r.data.meta;
  exigir(Array.isArray(rows) && objeto(m) && m.zona_horaria === ZONA_CLINICA && m.current_page === params.page && m.per_page === params.per_page
    && Number.isSafeInteger(m.total) && m.total >= 0 && m.last_page === Math.max(1, Math.ceil(m.total / params.per_page))
    && rows.length === Math.min(params.per_page, Math.max(0, m.total - (params.page - 1) * params.per_page)));
  const solicitudes = rows.map(row => proyectarSolicitud(row, rol));
  exigir(new Set(solicitudes.map(s => s.id_solicitud)).size === solicitudes.length);
  exigir(solicitudes.every(s => (!paciente || s.paciente.id_paciente === paciente) && (!consulta || s.id_consulta === consulta)));
  const result = { solicitudes, meta: { current_page: m.current_page, per_page: m.per_page, total: m.total, last_page: m.last_page, zona_horaria: ZONA_CLINICA } };
  if (paraSolicitar) {
    const e = r.data.data.edicion, o = e?.originales;
    exigir(objeto(e) && objeto(o) && objeto(e.acciones) && typeof e.acciones.solicitar === 'boolean' && Array.isArray(e.impedimentos)
      && (o.id_version_consulta === null || esIdentificador(o.id_version_consulta))
      && e.impedimentos.every(c => ['LAB_EDICION_OBSOLETA', 'LAB_ESTADO_NO_PERMITIDO'].includes(c))
      && new Set(e.impedimentos).size === e.impedimentos.length && e.acciones.solicitar === (e.impedimentos.length === 0)
      && (!e.acciones.solicitar || o.id_version_consulta !== null));
    result.edicion = { originales: { id_version_consulta: o.id_version_consulta }, acciones: { solicitar: e.acciones.solicitar }, impedimentos: [...e.impedimentos] };
  }
  return result;
}

export async function consultarContextoSolicitud({ id: cita, rol }, signal) {
  exigir([ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol)); id(cita);
  const epoca = obtenerEpocaSesion(), c = await consultarConsulta({ id: cita, rol }, signal);
  vigente(epoca, signal);
  exigir(c.consulta !== null);
  const lista = await listarSolicitudes({ rol, consulta: c.consulta.id_consulta, paraSolicitar: true }, signal);
  vigente(epoca, signal);
  const original = lista.edicion.originales.id_version_consulta;
  exigir((original === null || original === c.consulta.version_vigente.id_version_consulta)
    && lista.solicitudes.every(s => s.paciente.id_paciente === c.paciente.id_paciente && s.medico.id_medico === c.cita.medico.id_medico));
  // No conservar síntomas, antecedentes ni otros datos clínicos que este formulario no necesita.
  return { id_cita: cita, codigo_cita: c.cita.codigo_cita, inicio_at: c.cita.inicio_at, estado_cita: c.cita.estado,
    id_consulta: c.consulta.id_consulta, codigo_consulta: c.consulta.codigo_consulta, paciente: c.paciente, medico: c.cita.medico,
    edicion: lista.edicion, solicitudes: lista.solicitudes, meta: lista.meta, epocaSesion: epoca };
}

export function prepararSolicitud({ contexto, estudio, declaracion }) {
  const e = contexto?.edicion;
  exigir(e?.acciones.solicitar === true && e.impedimentos.length === 0 && esIdentificador(e.originales.id_version_consulta)
    && declaracion === true && texto(estudio) && [...estudio.trim()].length <= 255);
  return { estudio_solicitado: estudio.trim(), originales: { id_version_consulta: e.originales.id_version_consulta }, atencion_real_verificada: true };
}

export async function solicitarEstudio({ id: cita, rol, idActor, contexto, estudio, declaracion }, signal) {
  const sessionEpochExpected = contexto?.epocaSesion;
  let payload, objetivo;
  try {
    exigir([ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol) && esIdentificador(cita) && esIdentificador(idActor)
      && contexto?.id_cita === cita && esIdentificador(contexto.id_consulta) && esIdentificador(contexto.paciente?.id_paciente) && esIdentificador(contexto.medico?.id_medico)
      && Number.isInteger(sessionEpochExpected));
    vigente(sessionEpochExpected, signal); payload = prepararSolicitud({ contexto, estudio, declaracion });
    objetivo = { consulta: contexto.id_consulta, paciente: contexto.paciente.id_paciente, medico: contexto.medico.id_medico };
    await api.get(csrfURL, { signal, sessionBound: true, sessionEpochExpected });
    vigente(sessionEpochExpected, signal);
  } catch (error) { error.solicitudEnviada = false; throw error; }
  try {
    const r = await api.post(`/consultas/${objetivo.consulta}/solicitudes-laboratorio`, payload, { signal, sessionBound: true, sessionEpochExpected });
    vigente(sessionEpochExpected, signal); exigir(r.status === 201);
    const d = proyectarSolicitud(r.data?.data, rol, true);
    exigir(d.id_consulta === objetivo.consulta && d.paciente.id_paciente === objetivo.paciente && d.medico.id_medico === objetivo.medico
      && d.creada_por === idActor && d.estudio_solicitado === payload.estudio_solicitado && d.estado === 'Pendiente' && d.resultado === null && d.documento === null && d.ultima_revision === null);
    return d;
  } catch (error) { error.solicitudEnviada = true; throw error; }
}
export async function consultarSolicitud({ id: objetivo, rol, paraDocumentar = false, paraRevisar = false }, signal) {
  rolValido(rol); id(objetivo);
  if (paraDocumentar) exigir(rol === ROLES.ADMINISTRADOR);
  if (paraRevisar) exigir([ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol));
  const epoca = obtenerEpocaSesion(), r = await api.get(`/solicitudes-laboratorio/${objetivo}`, { signal, sessionBound: true, sessionEpochExpected: epoca });
  vigente(epoca, signal); exigir(r.status === 200);
  const d = proyectarSolicitud(r.data?.data, rol, true, paraDocumentar, paraRevisar); exigir(d.id_solicitud === objetivo);
  return paraDocumentar || paraRevisar ? { ...d, epocaSesion: epoca } : d;
}

export async function consultarVersionesResultado({ id: objetivo, rol, page = 1, per_page = 20 }, signal) {
  exigir([ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol)); id(objetivo);
  const params = filtros({ page, per_page }, rol), epoca = obtenerEpocaSesion();
  const r = await api.get(`/solicitudes-laboratorio/${objetivo}/versiones`, { params, signal, sessionBound: true, sessionEpochExpected: epoca });
  vigente(epoca, signal);
  const rows = r.data?.data, m = r.data?.meta;
  exigir(r.status === 200 && Array.isArray(rows) && objeto(m) && m.current_page === page && m.per_page === per_page && m.zona_horaria === ZONA_CLINICA
    && Number.isSafeInteger(m.total) && m.total >= 0 && m.last_page === Math.max(1, Math.ceil(m.total / per_page))
    && rows.length === Math.min(per_page, Math.max(0, m.total - (page - 1) * per_page)));
  const versiones = rows.map(v => {
    exigir(objeto(v) && esInstanteCita(v.registrada_en));
    const d = { id_version_resultado: id(v.id_version_resultado), numero_version: numero(v.numero_version), id_autor: id(v.id_autor), registrada_en: v.registrada_en };
    if (Object.hasOwn(v, 'motivo_cambio')) { exigir(v.motivo_cambio === null || (texto(v.motivo_cambio) && [...v.motivo_cambio].length <= 2000)); d.motivo_cambio = v.motivo_cambio; }
    if (rol === ROLES.ADMINISTRADOR) { d.id_version_origen = v.id_version_origen === null ? null : id(v.id_version_origen); exigir(Object.hasOwn(d, 'motivo_cambio')); }
    return d;
  });
  exigir(new Set(versiones.map(v => v.id_version_resultado)).size === versiones.length && versiones.every((v, i) => !i || v.numero_version < versiones[i - 1].numero_version));
  return { versiones, meta: { current_page: m.current_page, per_page: m.per_page, total: m.total, last_page: m.last_page, zona_horaria: ZONA_CLINICA } };
}

export async function obtenerArchivo({ solicitud, version, resultado = null }, signal) {
  id(solicitud); id(version);
  const epoca = obtenerEpocaSesion();
  let r;
  try {
    r = await api.get(`/solicitudes-laboratorio/${solicitud}/archivo`, { params: { id_version_resultado: version }, responseType: 'blob', signal,
      sessionBound: true, sessionEpochExpected: epoca });
  } catch (error) {
    vigente(epoca, signal);
    // Axios devuelve también los errores JSON como Blob. Nunca conservar/renderizar su cuerpo arbitrario.
    const blob = error.response?.data;
    let code;
    if (blob instanceof Blob && blob.size <= 65536 && /application\/(?:json|problem\+json)/i.test(blob.type)) {
      try { const value = JSON.parse(await blob.text()); if (typeof value?.error?.code === 'string') code = value.error.code; } catch { /* respuesta técnica o malformada */ }
    }
    vigente(epoca, signal);
    throw Object.assign(new Error('No se pudo descargar el documento.'), { response: error.response ? {
      status: error.response.status, headers: error.response.headers, data: { error: { code } },
    } : undefined });
  }
  vigente(epoca, signal);
  const blob = r.data, mime = r.headers?.['content-type']?.split(';')[0].trim().toLowerCase();
  exigir(r.status === 200 && blob instanceof Blob && Object.hasOwn(MIME_RESULTADOS, mime) && blob.type === mime
    && blob.size > 0 && blob.size <= MAX_RESULTADO);
  if (resultado) exigir(resultado.id_version_resultado === version && resultado.tipo_mime === mime && resultado.tamano_bytes === blob.size);
  const length = r.headers?.['content-length'];
  exigir(length === undefined || (String(blob.size) === String(length)));
  return { blob, nombre: `resultado-${version}.${MIME_RESULTADOS[mime]}` };
}
