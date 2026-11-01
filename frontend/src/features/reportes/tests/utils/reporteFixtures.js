export const sesion = (rol = 'Administrador', id_usuario = '7') => ({ id_usuario, rol, nombre_visible: 'Persona ficticia', correo_acceso: 'ficticia@example.test' });
export const medico = { id_medico: '9007199254740993', codigo_medico: 'MED-FICTICIO', nombres: 'Médica', apellidos: 'De prueba', activo: false };
export const especialidad = { id_especialidad: '5', nombre: 'Especialidad ficticia', activa: false };
export function cita(id = '1') {
  return { id_cita: id, codigo_cita: `CITA-${id}`, inicio_at: '2026-11-01T02:30:00.123456Z', fin_at: '2026-11-01T02:45:00.123456Z',
    creada_en: '2026-10-29T04:00:00.000000Z', estado: 'Programada', modalidad: 'Presencial', duracion_minutos: 15,
    sede_atencion: 'Sede ficticia', consultorio_atencion: 'Sala histórica', medico, especialidad,
    paciente: { id_paciente: '21', codigo_paciente: 'PAC-FICTICIO', nombres: 'Paciente', apellidos: 'De prueba' } };
}
export function pagina(data, params = {}, total = data.length) {
  const page = Number(params.page ?? 1), per_page = Number(params.per_page ?? 20);
  return { data, links: { first: '/primera', last: '/ultima', prev: null, next: null }, meta: {
    total, current_page: page, per_page, last_page: Math.max(1, Math.ceil(total / per_page)) } };
}
export function reporte(params = {}, total = 1, recurso = 'programadas') {
  const page = Number(params.page ?? 1), per_page = Number(params.per_page ?? 20);
  return { ...pagina(Array.from({ length: Math.min(per_page, Math.max(0, total - (page - 1) * per_page)) }, (_, i) => ({ ...cita(String((page - 1) * per_page + i + 1)), estado: params.estado || 'Programada' })), params, total),
    meta: { ...pagina([], params, total).meta, reporte: `citas/${recurso}`, zona_horaria: 'America/La_Paz', consultado_en: '2026-10-31T04:00:00.000000Z',
      filtros: { fecha: params.fecha ?? (params.desde ? null : '2026-10-31'), desde: params.desde ?? null, hasta: params.hasta ?? null,
        estado: recurso === 'programadas' ? 'Programada' : (params.estado || null), orden: params.orden ?? 'fecha_asc', id_medico: params.id_medico || null, id_especialidad: params.id_especialidad || null } } };
}
