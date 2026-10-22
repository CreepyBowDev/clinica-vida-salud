export const seleccion = { id_especialidad: '1', id_medico: '2', fecha: '2026-11-01', hora_inicio: '08:00:01', revision_turno: 'a'.repeat(64), motivo_breve: '0' };
export const propuesta = { id_propuesta: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', propuesta: 'SOBRE-FICTICIO-NO-PERSISTIR',
  paciente: { id_paciente: '3', codigo_paciente: 'PAC-FICTICIO', nombres: 'Paciente', apellidos: 'Ficticio' },
  medico: { id_medico: '2', codigo_medico: 'MED-FICTICIO', nombres: 'Médico', apellidos: 'Ficticio' },
  especialidad: { id_especialidad: '1', nombre: 'General ficticia' },
  consultorio: { id_consultorio: '4', codigo_consultorio: 'SALA-1', nombre: 'Sala ficticia' },
  inicio_at: '2026-11-01T12:00:01.000000Z', fin_at: '2026-11-01T12:15:01.000000Z',
  duracion_minutos: 15, zona_horaria: 'America/La_Paz', modalidad: 'Presencial', sede_atencion: 'Sede ficticia',
  direccion_sede: 'Dirección ficticia', motivo_breve: '0' };
export const cita = { id_cita: '9223372036854775807', id_paciente: '3', id_medico: '2', id_especialidad: '1', id_consultorio: '4',
  codigo_cita: 'CIT-FICTICIO', estado: 'Programada', modalidad: 'Presencial', duracion_minutos: 15,
  sede_atencion: 'Sede ficticia', consultorio_atencion: 'SALA-1 — Sala ficticia', motivo_breve: '0', version_programacion: 1,
  inicio_at: propuesta.inicio_at, fin_at: propuesta.fin_at, creada_en: '2026-11-01T11:00:00.000000Z', actualizada_en: '2026-11-01T11:00:00.000000Z' };
