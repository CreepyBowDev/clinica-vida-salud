export const dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
export function diaSemana(value) { return Number.isInteger(value) && value >= 1 && value <= 7 ? dias[value - 1] : 'No disponible'; }
export function horaLocal(value) { return typeof value === 'string' && /^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/.test(value) ? value : 'No disponible'; }
const sede = new Intl.DateTimeFormat('es-BO', { timeZone: 'America/La_Paz', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
export function fechaSede(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(value)) return 'No disponible';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) || date.toISOString() !== value.replace(/(\.\d{3})\d{3}Z$/, '$1Z') ? 'No disponible' : sede.format(date);
}
