import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it } from 'vitest';
import CitasTable from '../../components/CitasTable';
import { cita } from '../utils/agendaFixtures';

function mount(props = {}, rows = [cita]) {
  return render(<MemoryRouter><CitasTable citas={rows} caption="Agenda de prueba" {...props} /></MemoryRouter>);
}

it('el piloto destaca la hora de sede y conserva identificación, datos y destino del detalle', () => {
  mount({ destacarHorario: true });
  const table = screen.getByRole('table', { name: 'Agenda de prueba' });
  const link = within(table).getByRole('link', { name: 'Ver cita CIT-FICTICIA' });
  expect(link).toHaveTextContent('22:30'); expect(link).toHaveTextContent('22:45');
  expect(link).toHaveAttribute('href', `/citas/${cita.id_cita}`);
  expect(link.querySelector('time')).toHaveAttribute('datetime', cita.inicio_at);
  for (const text of ['31/10/2026', cita.codigo_cita, 'Paciente De prueba', cita.paciente.codigo_paciente,
    cita.especialidad.nombre, cita.consultorio_atencion, cita.sede_atencion, 'Presencial', 'Programada']) {
    expect(within(table).getByText(text, { exact: true })).toBeInTheDocument();
  }
  expect(within(table).getAllByRole('columnheader')).toHaveLength(6);
});

it('una cita que cruza medianoche muestra ambas fechas de sede', () => {
  mount({ destacarHorario: true }, [{ ...cita, inicio_at: '2026-11-01T03:50:00.000000Z', fin_at: '2026-11-01T04:05:00.000000Z' }]);
  expect(screen.getByText('31/10/2026 al 01/11/2026')).toBeInTheDocument();
  expect(screen.getByRole('link')).toHaveTextContent('23:50'); expect(screen.getByRole('link')).toHaveTextContent('00:05');
});

it('todos los estados conservan texto explícito, no solo una diferencia de color', () => {
  const estados = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
  mount({ destacarHorario: true }, estados.map((estado, index) => ({ ...cita, id_cita: String(index + 1), estado })));
  for (const estado of estados) expect(screen.getByText(estado, { exact: true })).toHaveAttribute('data-estado', estado);
});

it('historial y asistencia mantienen la presentación y destinos existentes fuera del piloto', () => {
  mount({ historial: true, detailPath: id => `/citas/${id}/asistencia` });
  expect(screen.getByRole('link', { name: 'Ver cita CIT-FICTICIA' })).toHaveTextContent('CIT-FICTICIA');
  expect(screen.getByRole('link')).toHaveAttribute('href', `/citas/${cita.id_cita}/asistencia`);
  expect(screen.getByText(/Registro:/)).toBeInTheDocument();
  expect(screen.getByText('Profesional Ficticio')).toBeInTheDocument();
  expect(screen.getByRole('table')).toHaveClass('catalog-table');
});
it('Mis citas destaca fecha local y conserva código, registro histórico y destino propio', () => {
  mount({ historial: true, destacarFecha: true, detailPath: id => `/mis-citas/${id}` });
  expect(screen.getByText('31/10/2026', { selector: '.history-date' })).toHaveAttribute('datetime', cita.inicio_at);
  expect(screen.getByRole('link', { name: 'Ver cita CIT-FICTICIA' })).toHaveTextContent(cita.codigo_cita);
  expect(screen.getByRole('link')).toHaveAttribute('href', `/mis-citas/${cita.id_cita}`);
  expect(screen.getByText(/Inicio: 22:30 · Fin: 22:45/)).toBeInTheDocument();
  expect(screen.getByText(/Registro:/)).toBeInTheDocument();
  expect(screen.getByText('Profesional Ficticio')).toBeInTheDocument();
});
it('Mis citas muestra la fecha de fin cuando la atención cruza medianoche de la sede', () => {
  mount({ historial: true, destacarFecha: true }, [{ ...cita, inicio_at: '2026-11-01T03:50:00.000000Z', fin_at: '2026-11-01T04:05:00.000000Z' }]);
  expect(screen.getByText('31/10/2026', { selector: '.history-date' })).toBeInTheDocument();
  expect(screen.getByText(/Inicio: 23:50 · Fin: 00:05 del 01\/11\/2026/)).toBeInTheDocument();
});
