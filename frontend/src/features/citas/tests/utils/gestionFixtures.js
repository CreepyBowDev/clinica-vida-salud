import { cita, detalle, sesion } from '../../../agenda/tests/utils/agendaFixtures';

export { cita, detalle, sesion };
export const gestion = () => ({ id_cita: cita.id_cita, codigo_cita: cita.codigo_cita,
  id_paciente: cita.paciente.id_paciente, id_medico: cita.medico.id_medico, id_especialidad: cita.especialidad.id_especialidad, id_consultorio: '8',
  inicio_at: cita.inicio_at, fin_at: cita.fin_at, estado: cita.estado, modalidad: cita.modalidad, duracion_minutos: cita.duracion_minutos,
  sede_atencion: cita.sede_atencion, consultorio_atencion: cita.consultorio_atencion, version_programacion: 1,
  actualizada_en: '2026-10-26T12:00:00.123456Z', consultado_en: '2026-10-26T12:00:01.000000Z', zona_horaria: 'America/La_Paz',
  acciones: { reprogramar: true, cancelar: true }, impedimentos: [], ultimo_cambio: null });

export function alternativas(original = gestion(), { fecha = '2026-11-02', page = 1, per_page = 12, total = 1 } = {}) {
  const { modalidad, zona_horaria, consultado_en, acciones, impedimentos, ultimo_cambio, ...cita_original } = original;
  const turnos = Array.from({ length: Math.min(per_page, Math.max(0, total - (page - 1) * per_page)) }, (_, index) => {
    const minuto = ((page - 1) * per_page + index) * 20;
    const inicio_at = `${fecha}T${String(12 + Math.floor(minuto / 60)).padStart(2, '0')}:${String(minuto % 60).padStart(2, '0')}:00.000000Z`;
    const fin_at = `${fecha}T${String(12 + Math.floor((minuto + 20) / 60)).padStart(2, '0')}:${String((minuto + 20) % 60).padStart(2, '0')}:00.000000Z`;
    return { inicio_at, fin_at, hora_inicio: `${String(8 + Math.floor(minuto / 60)).padStart(2, '0')}:${String(minuto % 60).padStart(2, '0')}:00`, revision_turno: 'a'.repeat(64) };
  });
  return { data: { fecha, zona_horaria, consultado_en, cita_original,
    paciente: structuredClone(cita.paciente), medico: structuredClone(cita.medico), especialidad: structuredClone(cita.especialidad),
    consultorio: { id_consultorio: '10', codigo_consultorio: 'NUEVO', nombre: 'Sala nueva' },
    sede_atencion: 'Sede vigente', direccion_sede: null, duracion_minutos: 20, turnos },
  meta: { current_page: page, per_page, total, last_page: Math.max(1, Math.ceil(total / per_page)) } };
}

export function reprogramada(vista = alternativas().data, turno = vista.turnos[0]) {
  return { ...vista.cita_original, inicio_at: turno.inicio_at, fin_at: turno.fin_at, estado: 'Programada', modalidad: 'Presencial',
    id_consultorio: vista.consultorio.id_consultorio, consultorio_atencion: `${vista.consultorio.codigo_consultorio} — ${vista.consultorio.nombre}`,
    sede_atencion: vista.sede_atencion, duracion_minutos: vista.duracion_minutos,
    version_programacion: vista.cita_original.version_programacion + 1, actualizada_en: '2026-10-27T12:00:00.000001Z',
    ultimo_cambio: { id_cambio_cita: '12', id_actor: '1', operacion: 'REPROGRAMACION', realizado_en: '2026-10-27T12:00:00.000000Z' } };
}

export function cancelada(original = gestion()) {
  const { zona_horaria, consultado_en, acciones, impedimentos, ...programacion } = original;
  return { ...programacion, estado: 'Cancelada', actualizada_en: '2026-10-27T12:00:00.000001Z',
    ultimo_cambio: { id_cambio_cita: '13', id_actor: '1', operacion: 'CANCELACION', realizado_en: '2026-10-27T12:00:00.000000Z' } };
}
