export const instante = '2026-10-30T14:00:00.000000Z';
export function solicitud(extra = {}) {
  return { id_solicitud: '71', id_consulta: '44', estudio_solicitado: 'Hemograma de prueba', solicitada_en: instante,
    paciente: { id_paciente: '21', codigo_paciente: 'PAC-021', nombres: 'Persona', apellidos: 'Ficticia' },
    medico: { id_medico: '31', codigo_medico: 'MED-031', nombres: 'Profesional', apellidos: 'Ficticio' },
    estado: 'Pendiente', resultado: null, creada_por: '9', edicion: { vigente: null, ultima_revision: null, acciones: { cargar: true, corregir: false, reasociar: false, revisar: false }, originales: { estado_interno: 'Pendiente', id_version_actual: null, id_ultima_revision: null, ultima_version: 0 } }, ...extra };
}
export function disponible() {
  return solicitud({ estado: 'Disponible', resultado: { id_version_resultado: '81', numero_version: 1, publicado_en: instante, tipo_mime: 'application/pdf', tamano_bytes: 8 },
    edicion: { vigente: { id_version_resultado: '81', numero_version: 1 }, ultima_revision: { id_revision: '91', decision: 'Autorizar', motivo: null, id_medico_responsable: '31', revisada_por: '10', revisada_en: instante }, acciones: { cargar: false, corregir: false, reasociar: false, revisar: false }, originales: { estado_interno: 'Disponible', id_version_actual: '81', id_ultima_revision: '91', ultima_version: 1 } } });
}
export function documentada({ devuelta = false, numero = 1, version = '81', ...extra } = {}) {
  return solicitud({ estado: 'En revisión', edicion: { vigente: { id_version_resultado: version, numero_version: numero },
    ultima_revision: devuelta ? { id_revision: '91', decision: 'Devolver', motivo: 'Documento ficticio ilegible', id_medico_responsable: '31', revisada_por: '10', revisada_en: instante } : null,
    acciones: { cargar: false, corregir: devuelta, reasociar: devuelta, revisar: !devuelta },
    originales: { estado_interno: 'En revisión', id_version_actual: version, id_ultima_revision: devuelta ? '91' : null, ultima_version: numero } }, ...extra });
}
export const listado = rows => ({ data: rows, meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1, zona_horaria: 'America/La_Paz' } });
export function contextoSolicitud(epocaSesion = 0) {
  const s = solicitud();
  return { id_cita: '41', codigo_cita: 'CIT-041', inicio_at: instante, estado_cita: 'Atendida', id_consulta: '44', codigo_consulta: 'CON-044',
    paciente: s.paciente, medico: s.medico, epocaSesion,
    edicion: { originales: { id_version_consulta: '61' }, acciones: { solicitar: true }, impedimentos: [] }, solicitudes: [], meta: listado([]).meta };
}
