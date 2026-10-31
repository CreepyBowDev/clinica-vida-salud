import { api, csrfURL } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarCita } from '../../agenda/services/agenda.service';
import { esInstanteCita, ESTADOS_CITA, ZONA_CLINICA } from '../../agenda/utils/agendaFormat';

const exigir = condition => { if (!condition) throw new Error('No se pudo verificar la asistencia de la cita.'); };
const objeto = value => value && typeof value === 'object' && !Array.isArray(value);
const texto = value => typeof value === 'string' && value.trim().length > 0;
const roles = [ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA, ROLES.MEDICO];
const condiciones = {
  confirmar_llegada: ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_CON_ATENCION_DOCUMENTADA', 'CITA_LLEGADA_FUERA_DE_PLAZO'],
  registrar_inasistencia: ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_CON_ATENCION_DOCUMENTADA', 'CITA_INASISTENCIA_ANTICIPADA'],
  marcar_atendida: ['CITA_TRANSICION_NO_PERMITIDA', 'CITA_ATENCION_ANTICIPADA'],
};

function programacion(d) {
  exigir(objeto(d));
  const result = {};
  for (const campo of ['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio']) {
    exigir(esIdentificador(d[campo])); result[campo] = d[campo];
  }
  for (const campo of ['codigo_cita', 'sede_atencion', 'consultorio_atencion']) { exigir(texto(d[campo])); result[campo] = d[campo]; }
  for (const campo of ['inicio_at', 'fin_at', 'actualizada_en']) { exigir(esInstanteCita(d[campo])); result[campo] = d[campo]; }
  exigir(d.inicio_at < d.fin_at && ESTADOS_CITA.includes(d.estado) && d.modalidad === 'Presencial'
    && Number.isInteger(d.duracion_minutos) && d.duracion_minutos > 0
    && Number.isInteger(d.version_programacion) && d.version_programacion > 0 && d.version_programacion <= 2147483647);
  return { ...result, estado: d.estado, modalidad: d.modalidad, duracion_minutos: d.duracion_minutos, version_programacion: d.version_programacion };
}

function ultimoCambio(cambio) {
  if (cambio === null) return null;
  exigir(objeto(cambio) && esIdentificador(cambio.id_cambio_cita) && esIdentificador(cambio.id_actor)
    && texto(cambio.operacion) && esInstanteCita(cambio.realizado_en));
  return { id_cambio_cita: cambio.id_cambio_cita, id_actor: cambio.id_actor, operacion: cambio.operacion, realizado_en: cambio.realizado_en };
}

export async function consultarAsistenciaCita({ id, rol }, signal) {
  exigir(esIdentificador(id) && roles.includes(rol));
  const response = await api.get(`/citas/${id}/asistencia`, { signal, sessionBound: true });
  const d = response.data?.data, cita = programacion(d);
  exigir(response.status === 200 && cita.id_cita === id && d.zona_horaria === ZONA_CLINICA
    && esInstanteCita(d.consultado_en) && objeto(d.acciones) && objeto(d.impedimentos));
  const acciones = {}, impedimentos = {};
  for (const [accion, codigos] of Object.entries(condiciones)) {
    const permitida = accion === 'marcar_atendida' ? rol !== ROLES.RECEPCIONISTA : rol !== ROLES.MEDICO;
    const lista = d.impedimentos[accion];
    exigir(typeof d.acciones[accion] === 'boolean' && Array.isArray(lista) && new Set(lista).size === lista.length
      && lista.every(code => (permitida ? codigos : ['ACCESO_DENEGADO']).includes(code))
      && d.acciones[accion] === (lista.length === 0)
      && (permitida || (lista.length === 1 && lista[0] === 'ACCESO_DENEGADO')));
    acciones[accion] = d.acciones[accion]; impedimentos[accion] = [...lista];
  }
  // Elegibilidad del servidor: no se calcula con el reloj del navegador.
  return { ...cita, zona_horaria: d.zona_horaria, consultado_en: d.consultado_en,
    acciones, impedimentos, ultimo_cambio: ultimoCambio(d.ultimo_cambio) };
}

export async function consultarContextoAsistencia({ id, rol }, signal) {
  exigir(esIdentificador(id) && roles.includes(rol));
  const [asistencia, detalle] = await Promise.all([consultarAsistenciaCita({ id, rol }, signal), consultarCita({ id, rol }, signal)]);
  exigir(asistencia.id_cita === detalle.id_cita && asistencia.codigo_cita === detalle.codigo_cita
    && asistencia.id_paciente === detalle.paciente.id_paciente && asistencia.id_medico === detalle.medico.id_medico
    && asistencia.id_especialidad === detalle.especialidad.id_especialidad);
  const persona = value => ({ nombres: value.nombres, apellidos: value.apellidos });
  // El detalle solo aporta etiquetas; no conservar CI/contactos/motivos ni mezclar programación de dos GET.
  return { asistencia, paciente: persona(detalle.paciente), medico: persona(detalle.medico), especialidad: { nombre: detalle.especialidad.nombre } };
}

async function escribirAsistencia({ id, rol, original, declaracion, idActor }, signal, { accion, campo, ruta, estado, operacion }) {
  const atencion = operacion === 'ATENCION';
  exigir(esIdentificador(id) && [ROLES.ADMINISTRADOR, atencion ? ROLES.MEDICO : ROLES.RECEPCIONISTA].includes(rol)
    && declaracion === true && esIdentificador(idActor));
  const anterior = programacion(original);
  exigir(anterior.id_cita === id && (atencion ? ['Programada', 'Confirmada'].includes(anterior.estado) : anterior.estado === 'Programada') && original.acciones?.[accion] === true
    && Array.isArray(original.impedimentos?.[accion]) && original.impedimentos[accion].length === 0);
  const payload = { version_programacion_original: anterior.version_programacion,
    actualizada_en_original: anterior.actualizada_en, [campo]: true };
  try {
    signal?.throwIfAborted();
    await api.get(csrfURL, { signal, sessionBound: true });
    signal?.throwIfAborted();
  } catch (error) { error.asistenciaEnviada = false; throw error; }
  try {
    const response = await api.patch(`/citas/${id}/${ruta}`, payload, { signal, sessionBound: true });
    const d = response.data?.data, cita = programacion(d), cambio = ultimoCambio(d.ultimo_cambio);
    exigir(response.status === 200 && cita.estado === estado && cita.actualizada_en > anterior.actualizada_en
      && Object.keys(anterior).filter(key => !['estado', 'actualizada_en'].includes(key)).every(key => cita[key] === anterior[key])
      && cambio?.operacion === operacion && cambio.id_actor === idActor);
    return { ...cita, ultimo_cambio: cambio };
  } catch (error) { error.asistenciaEnviada = true; throw error; }
}

export function confirmarLlegada(entrada, signal) {
  return escribirAsistencia(entrada, signal, { accion: 'confirmar_llegada', campo: 'presencia_verificada',
    ruta: 'llegada', estado: 'Confirmada', operacion: 'LLEGADA' });
}

export function registrarInasistencia(entrada, signal) {
  return escribirAsistencia(entrada, signal, { accion: 'registrar_inasistencia', campo: 'ausencia_verificada',
    ruta: 'inasistencia', estado: 'Inasistencia', operacion: 'INASISTENCIA' });
}

export function marcarAtendida(entrada, signal) {
  return escribirAsistencia(entrada, signal, { accion: 'marcar_atendida', campo: 'atencion_realizada',
    ruta: 'atencion', estado: 'Atendida', operacion: 'ATENCION' });
}
