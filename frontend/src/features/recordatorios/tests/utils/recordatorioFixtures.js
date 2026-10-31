export const sesion = (rol = 'Paciente', id_usuario = '7') => ({ id_usuario, rol, nombre_visible: 'Persona ficticia', correo_acceso: 'ficticia@example.test' });
export const aviso = (codigo = 'CITA-FICTICIA', id = '9007199254740993') => ({
  id_recordatorio: id, id_cita: id, version_programacion: 1, generado_en: '2026-10-30T02:30:00.123456Z',
  cita: { codigo_cita: codigo, inicio_at: '2026-10-31T02:30:00.123456Z', fin_at: '2026-10-31T02:45:00.123456Z',
    estado: 'Programada', modalidad: 'Presencial', sede_atencion: 'Sede ficticia',
    consultorio: { id_consultorio: '4', ubicacion: 'Sala ficticia' },
    medico: { id_medico: '12', codigo_medico: 'MED-FICTICIO', nombres: 'Médica', apellidos: 'De prueba' },
    especialidad: { id_especialidad: '5', nombre: 'Especialidad ficticia' } },
});
export function listado(data = [aviso()], params = {}) {
  const { page = 1, per_page = 20, fecha_desde = null, fecha_hasta = null, total = data.length } = params;
  return { data, meta: { current_page: page, per_page, total, last_page: Math.max(1, Math.ceil(total / per_page)),
    zona_horaria: 'America/La_Paz', consultado_en: '2026-10-30T02:30:00.123456Z', filtros: { fecha_desde, fecha_hasta },
    paciente: { id_paciente: '21', codigo_paciente: 'PAC-FICTICIO', nombres: 'Paciente', apellidos: 'De prueba' } } };
}
