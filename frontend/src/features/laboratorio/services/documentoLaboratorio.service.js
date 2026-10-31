import { api, csrfURL, obtenerEpocaSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarSolicitud, proyectarSolicitud, MAX_RESULTADO } from './laboratorio.service';

export const ACCIONES_DOCUMENTO = Object.freeze({ cargar: 'Cargar documento', sustituir: 'Sustituir documento', reasociar: 'Corregir asociación' });
function exigir(v) { if (!v) throw new Error('No se pudo verificar la operación documental.'); }
export function validarArchivoLaboratorio(file) {
  if (!(file instanceof File) || file.size < 1 || file.size > MAX_RESULTADO) return 'Selecciona un archivo de hasta 10 MiB, no vacío.';
  const ext = file.name.split('.').at(-1)?.toLowerCase(), mime = { pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png' }[ext];
  if (!mime || (file.type && file.type !== mime) || [...file.name].length > 255 || /[\p{C}\\/]/u.test(file.name)) return 'Selecciona un PDF, JPEG o PNG con nombre y formato válidos.';
  return null;
}
export function originalesDocumento(ctx) {
  const o = ctx?.edicion?.originales;
  exigir(o && o.estado_interno === ctx.estado && ['Pendiente', 'En revisión', 'Disponible'].includes(o.estado_interno)
    && (o.id_version_actual === null || esIdentificador(o.id_version_actual)) && (o.id_ultima_revision === null || esIdentificador(o.id_ultima_revision))
    && o.id_version_actual === (ctx.documento?.id_version_resultado ?? null) && o.id_ultima_revision === (ctx.ultima_revision?.id_revision ?? null)
    && Number.isInteger(o.ultima_version) && o.ultima_version >= 0 && o.ultima_version <= 2147483647);
  return { estado_interno: o.estado_interno, id_version_actual: o.id_version_actual, id_ultima_revision: o.id_ultima_revision, ultima_version: o.ultima_version };
}
export function esDestinoDocumento(d, origen) {
  return Boolean(d && d.id_solicitud !== origen.id_solicitud && d.estado === 'Pendiente' && d.documento === null
    && d.edicion?.originales.ultima_version === 0 && d.edicion.originales.id_version_actual === null && d.edicion.originales.id_ultima_revision === null);
}
export function prepararDocumento({ contexto, tipo, archivo, motivo = '', asociacion, destino, asociacionDestino }) {
  exigir(Object.hasOwn(ACCIONES_DOCUMENTO, tipo) && esIdentificador(contexto?.id_solicitud));
  const originales = originalesDocumento(contexto);
  exigir(contexto.edicion.acciones[tipo === 'sustituir' ? 'corregir' : tipo] === true && asociacion === true);
  if (tipo !== 'cargar') exigir(contexto.estado === 'En revisión' && contexto.documento !== null && contexto.ultima_revision?.decision === 'Devolver');
  const requiereMotivo = tipo !== 'cargar' || originales.ultima_version > 0;
  exigir(typeof motivo === 'string' && [...motivo.trim()].length <= 2000 && (!requiereMotivo || motivo.trim().length > 0));
  const razon = requiereMotivo ? { motivo_cambio: motivo.trim() } : {};
  if (tipo === 'reasociar') {
    exigir(archivo === undefined || archivo === null);
    exigir(esDestinoDocumento(destino, contexto) && asociacionDestino === true && esIdentificador(destino.id_solicitud) && destino.epocaSesion === contexto.epocaSesion);
    return { tipo, originales, asociacion_verificada: 1, ...razon, id_solicitud_destino: destino.id_solicitud,
      originales_destino: originalesDocumento(destino), asociacion_destino_verificada: 1 };
  }
  exigir(validarArchivoLaboratorio(archivo) === null);
  const body = new FormData(); body.append('archivo', archivo);
  if (tipo === 'sustituir') body.append('tipo', tipo);
  body.append('asociacion_verificada', '1');
  if (razon.motivo_cambio) body.append('motivo_cambio', razon.motivo_cambio);
  for (const [k, v] of Object.entries(originales)) body.append(`originales[${k}]`, v === null ? '' : String(v));
  return body;
}
function vigente(epoca, signal) {
  if (signal?.aborted || epoca !== obtenerEpocaSesion()) throw Object.assign(new Error('El contexto cambió.'), { code: 'SESSION_SUPERSEDED' });
}
function mismaSolicitud(a, b) {
  exigir(a.id_solicitud === b.id_solicitud && a.id_consulta === b.id_consulta && a.paciente.id_paciente === b.paciente.id_paciente
    && a.medico.id_medico === b.medico.id_medico && a.estudio_solicitado === b.estudio_solicitado && a.estado === 'En revisión' && a.resultado === null);
}
export async function guardarDocumento({ rol, contexto, ...entrada }, signal) {
  const epoca = contexto?.epocaSesion;
  let payload, origen, destino, objetivo, destinoId, ultima;
  try {
    exigir(rol === ROLES.ADMINISTRADOR && Number.isInteger(epoca) && esIdentificador(contexto?.id_consulta)
      && esIdentificador(contexto.paciente?.id_paciente) && esIdentificador(contexto.medico?.id_medico)); vigente(epoca, signal);
    payload = prepararDocumento({ contexto, ...entrada });
    origen = structuredClone(contexto); destino = entrada.destino ? structuredClone(entrada.destino) : null;
    objetivo = origen.id_solicitud; destinoId = destino?.id_solicitud; ultima = origen.edicion.originales.ultima_version;
    await api.get(csrfURL, { signal, sessionBound: true, sessionEpochExpected: epoca }); vigente(epoca, signal);
  } catch (error) { error.documentoEnviado = false; throw error; }
  let respondio = false;
  try {
    const r = await api.post(`/solicitudes-laboratorio/${objetivo}/${entrada.tipo === 'cargar' ? 'documentos' : 'correcciones'}`, payload,
      { signal, sessionBound: true, sessionEpochExpected: epoca });
    respondio = true; vigente(epoca, signal); exigir(r.status === 201);
    const actual = proyectarSolicitud(r.data?.data, rol, true, true); mismaSolicitud(actual, origen);
    if (entrada.tipo === 'reasociar') {
      exigir(actual.documento === null && actual.ultima_revision === null && actual.edicion.originales.ultima_version === ultima);
      const resumen = proyectarSolicitud(r.data?.data?.destino, rol); mismaSolicitud(resumen, destino);
      const receptor = await consultarSolicitud({ id: destinoId, rol, paraDocumentar: true }, signal); vigente(epoca, signal);
      mismaSolicitud(receptor, destino); exigir(receptor.documento?.numero_version === 1 && receptor.ultima_revision === null);
      return { origen: actual, destino: receptor };
    }
    exigir(actual.documento?.numero_version === ultima + 1 && actual.documento.id_version_resultado !== origen.documento?.id_version_resultado && actual.ultima_revision === null);
    return { origen: actual, destino: null };
  } catch (error) { error.documentoEnviado = true; error.documentoRespuestaIncierta = respondio; throw error; }
}
export async function consultarEstadoDocumento({ id, destino }, signal) {
  const epoca = obtenerEpocaSesion(), rol = ROLES.ADMINISTRADOR;
  const origen = await consultarSolicitud({ id, rol, paraDocumentar: true }, signal); vigente(epoca, signal);
  const receptor = destino ? await consultarSolicitud({ id: destino, rol, paraDocumentar: true }, signal) : null; vigente(epoca, signal);
  return { origen, destino: receptor };
}
