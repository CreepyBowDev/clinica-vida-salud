import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import PatientsPage from '../../pages/PatientsPage';

const patient = { id_paciente: '9223372036854775807', codigo_paciente: 'PAC-PRUEBA', nombres: 'Persona', apellidos: 'Ficticia', documento_identidad: null, activo: false };
vi.mock('../../hooks/usePatientQuery', () => ({ usePatientQuery: () => ({
  data: { data: [patient], meta: { total: 1, current_page: 1, last_page: 1 } }, loading: false, reload: vi.fn(),
}) }));

it.each([
  [{ historial: true }, '/recepcion/pacientes/9223372036854775807'],
  [{ gestion: true }, '/recepcion/pacientes/9223372036854775807'],
  [{ historia: true }, '/historia-clinica/pacientes/9223372036854775807'],
  [{ laboratorio: true }, '/laboratorio/pacientes/9223372036854775807'],
])('el selector %j conserva destino y no recibe estilos del directorio administrativo', (props, href) => {
  const { container } = render(<MemoryRouter><PatientsPage {...props} /></MemoryRouter>);
  expect(container.querySelector('.directory-page')).not.toBeInTheDocument();
  expect(screen.getByRole('table').parentElement).toHaveClass('query-table');
  expect(screen.getByRole('link', { name: /^Ver (ficha|historia clínica|estudios) de/ })).toHaveAttribute('href', href);
  expect(screen.getByText('Sin CI registrado')).toBeInTheDocument();
  expect(screen.getByText('Inactiva', { selector: '.patient-status' })).toBeInTheDocument();
});

it('selección documental conserva textos y callback sin abrir una ficha ni adoptar estilos del directorio', () => {
  const onSeleccionar = vi.fn();
  const { container } = render(<MemoryRouter><PatientsPage onSeleccionar={onSeleccionar} tituloSeleccion="Destino documental de prueba" descripcionSeleccion="Comprueba la ficha de destino." /></MemoryRouter>);
  expect(container.querySelector('.directory-page')).not.toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Destino documental de prueba' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Seleccionar paciente Persona Ficticia' }));
  expect(onSeleccionar).toHaveBeenCalledExactlyOnceWith(patient);
  expect(screen.queryByRole('link', { name: /^Ver ficha/ })).not.toBeInTheDocument();
});
