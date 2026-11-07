import * as servicios from '../../services/reporte.service';
import { reporte, medico } from './reporteFixtures';
import { pacientesReporte } from './pacientesReporteFixtures';
import { agendaReporte, cantidadesReporte } from './cantidadesAgendaFixtures';

export const variantes = [
  ['programadas', servicios.consultarCitasProgramadas, {}, (p, n) => reporte(p, n)],
  ['por-medico', servicios.consultarCitasPorMedico, { id_medico: medico.id_medico }, (p, n) => reporte(p, n, 'por-medico')],
  ['por-especialidad', servicios.consultarCitasPorEspecialidad, { id_especialidad: '5' }, (p, n) => reporte(p, n, 'por-especialidad')],
  ['por-estado', servicios.consultarCitasPorEstado, { estado: 'Cancelada' }, (p, n) => reporte(p, n, 'por-estado')],
  ['pacientes-registrados', servicios.consultarPacientesRegistrados, {}, pacientesReporte],
  ['cantidades', servicios.consultarCantidadesCitas, {}, (p, n) => cantidadesReporte(p, { atendidas: n, canceladas: 0, inasistencias: 0, total: n })],
  ['agenda-diaria', servicios.consultarAgendaDiaria, { id_medico: medico.id_medico }, agendaReporte],
];
