import { ROLES } from './roles';

const { ADMINISTRADOR: A, RECEPCIONISTA: R, MEDICO: M } = ROLES;
export const CITA_OPERATIONS = {
  reprogramar: { group: 'gestion', label: 'Reprogramar una cita', roles: [A, R], mode: 'reprogramacion', capability: 'reprogramar' },
  cancelar: { group: 'gestion', label: 'Cancelar una cita', roles: [A, R], mode: 'cancelacion', capability: 'cancelar' },
  llegada: { group: 'asistencia', label: 'Confirmar la llegada del Paciente', roles: [A, R], mode: 'LLEGADA', capability: 'confirmar_llegada' },
  inasistencia: { group: 'asistencia', label: 'Registrar la inasistencia del Paciente', roles: [A, R], mode: 'INASISTENCIA', capability: 'registrar_inasistencia' },
  atendida: { group: 'asistencia', label: 'Marcar una cita como Atendida', roles: [A, M], mode: 'ATENCION', capability: 'marcar_atendida' },
};

export function citaOperation(search, group, role) {
  const values = new URLSearchParams(search).getAll('operacion');
  if (!values.length) return { valid: true, operation: null, search: '' };
  const operation = CITA_OPERATIONS[values[0]];
  if (values.length !== 1 || !operation || operation.group !== group || !operation.roles.includes(role)) return { valid: false, operation: null, search: '' };
  return { valid: true, operation, search: `?operacion=${values[0]}` };
}
