export const cita = {
  id_cita: '9223372036854775807', codigo_cita: 'CIT-FICTICIA', inicio_at: '2026-11-01T02:30:00.123456Z', fin_at: '2026-11-01T02:45:00.123456Z',
  creada_en: '2026-10-22T12:00:00.000000Z', duracion_minutos: 15, estado: 'Programada', modalidad: 'Presencial',
  sede_atencion: 'Sede ficticia', consultorio_atencion: 'Sala conservada ficticia',
  medico: { id_medico: '9007199254740993', codigo_medico: 'MED-FICTICIO', nombres: 'Profesional', apellidos: 'Ficticio' },
  paciente: { id_paciente: '31', codigo_paciente: 'PAC-FICTICIO', nombres: 'Paciente', apellidos: 'De prueba' },
  especialidad: { id_especialidad: '9', nombre: 'Especialidad ficticia' },
};
export const detalle = rol => ({ ...structuredClone(cita), ...(rol === 'Administrador' ? {} : { motivo_breve: 'MOTIVO-FICTICIO' }),
  paciente: { ...cita.paciente, ...(rol === 'Recepcionista' ? { documento_identidad: null, telefono: '70000000' } : {}),
    ...(rol === 'Médico' ? { fecha_nacimiento: '1990-01-01' } : {}) } });
export const sesion = (rol = 'Paciente', id = '1') => ({ id_usuario: id, rol, nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test' });
export function pagina(data = [structuredClone(cita)], extra = {}) {
  return structuredClone({ data, links: { first: '/ejemplo?page=1', last: '/ejemplo?page=1', prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, from: data.length ? 1 : null, to: data.length || null,
      zona_horaria: 'America/La_Paz', consultado_en: '2026-11-01T12:00:00.000000Z', ...extra } });
}
