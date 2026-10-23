export const ZONA_CLINICA = 'America/La_Paz';
export const ESTADOS_CITA = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
export const PERIODOS_CITA = ['proximas', 'anteriores', 'todas'];

export function esFechaCivil(value) {
  if (typeof value !== 'string' || !/^(?!0000)\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split('-').map(Number);
  const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
  return month >= 1 && month <= 12 && day >= 1 && day <= [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1];
}

export function esInstanteCita(value) {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d:[0-5]\d\.\d{6}Z$/.test(value)
    && esFechaCivil(value.slice(0, 10)) && Number.isFinite(Date.parse(value));
}

export function formatearInstanteCita(value) {
  if (!esInstanteCita(value)) return 'No disponible';
  return new Intl.DateTimeFormat('es-BO', { timeZone: ZONA_CLINICA, day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(value));
}

export function formatearFechaCivil(value) {
  return esFechaCivil(value) ? value.split('-').reverse().join('/') : 'No registrado';
}
