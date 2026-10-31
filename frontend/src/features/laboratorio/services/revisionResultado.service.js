import { api, csrfURL, obtenerEpocaSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarSolicitud, proyectarSolicitud } from './laboratorio.service';

export const DECISIONES_RESULTADO = Object.freeze({ Autorizar: 'Autorizar publicación', Devolver: 'Devolver para corrección' });
function exigir(value) { if (!value) throw new Error('No se pudo verificar la revisión del resultado.'); }
function vigente(epoca, signal) {
  if (signal?.aborted || epoca !== obtenerEpocaSesion()) throw Object.assign(new Error('El contexto cambió.'), { code: 'SESSION_SUPERSEDED' });
}
export function prepararRevision({ contexto, decision, motivo = '', declaracion }) {
  const e = contexto?.revision, o = e?.originales, d = contexto?.documento;
  exigir(Object.hasOwn(DECISIONES_RESULTADO, decision) && declaracion === true && e?.acciones.revisar === true
    && contexto.estado === 'En revisión' && contexto.ultima_revision === null && d && esIdentificador(d.id_version_resultado)
    && o?.estado_interno === contexto.estado && o.id_version_actual === d.id_version_resultado && o.id_ultima_revision === null
    && Number.isInteger(o.ultima_version) && o.ultima_version > 0 && o.ultima_version <= 2147483647 && o.ultima_version === d.numero_version);
  if (decision === 'Devolver') exigir(typeof motivo === 'string' && motivo.trim().length > 0 && [...motivo.trim()].length <= 2000);
  return { originales: { estado_interno: o.estado_interno, id_version_actual: o.id_version_actual, id_ultima_revision: null, ultima_version: o.ultima_version },
    decision, correspondencia_verificada: true, ...(decision === 'Devolver' ? { motivo: motivo.trim() } : {}) };
}
export async function revisarResultado({ id, rol, idActor, contexto, ...entrada }, signal) {
  const epoca = contexto?.epocaSesion;
  let payload, anterior;
  try {
    exigir([ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol) && esIdentificador(id) && esIdentificador(idActor) && contexto?.id_solicitud === id
      && esIdentificador(contexto.id_consulta) && esIdentificador(contexto.paciente?.id_paciente) && esIdentificador(contexto.medico?.id_medico) && Number.isInteger(epoca));
    vigente(epoca, signal); payload = prepararRevision({ contexto, ...entrada }); anterior = structuredClone(contexto);
    await api.get(csrfURL, { signal, sessionBound: true, sessionEpochExpected: epoca }); vigente(epoca, signal);
  } catch (error) { error.revisionEnviada = false; throw error; }
  try {
    const r = await api.post(`/solicitudes-laboratorio/${id}/revisiones`, payload, { signal, sessionBound: true, sessionEpochExpected: epoca });
    vigente(epoca, signal); exigir(r.status === 201);
    const d = proyectarSolicitud(r.data?.data, rol, true, false, true), revision = d.ultima_revision;
    exigir(d.id_solicitud === id && d.id_consulta === anterior.id_consulta && d.paciente.id_paciente === anterior.paciente.id_paciente
      && d.medico.id_medico === anterior.medico.id_medico && d.estudio_solicitado === anterior.estudio_solicitado
      && d.documento?.id_version_resultado === anterior.documento.id_version_resultado && d.documento.numero_version === anterior.documento.numero_version
      && revision?.decision === payload.decision && revision.revisada_por === idActor && revision.id_medico_responsable === anterior.medico.id_medico
      && revision.motivo === (payload.motivo ?? null) && d.revision.acciones.revisar === false);
    if (payload.decision === 'Autorizar') exigir(d.estado === 'Disponible' && d.resultado?.id_version_resultado === anterior.documento.id_version_resultado && d.resultado.publicado_en === revision.revisada_en);
    else exigir(d.estado === 'En revisión' && d.resultado === null);
    return d;
  } catch (error) { error.revisionEnviada = true; throw error; }
}
export async function consultarEstadoRevision({ id, rol }, signal) {
  return consultarSolicitud({ id, rol, paraRevisar: true }, signal);
}
