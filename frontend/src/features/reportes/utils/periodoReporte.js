import { esFechaCivil } from '../../agenda/utils/agendaFormat';

function diaCivil(fecha) {
  const [year, month, day] = fecha.split('-').map(Number);
  const date = new Date(0);
  date.setUTCFullYear(year, month - 1, day);
  return date.getTime() / 86400000;
}

// Validación de entrada civil, no conversión de horarios de las citas.
export function esPeriodoReporte({ fecha, desde, hasta }) {
  if (fecha !== undefined) return desde === undefined && hasta === undefined && esFechaCivil(fecha);
  return esFechaCivil(desde) && esFechaCivil(hasta) && desde <= hasta && diaCivil(hasta) - diaCivil(desde) < 366;
}
