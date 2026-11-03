import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import PatientForm from '../../components/PatientForm';

const draft = { documento_identidad: '', nombres: '', apellidos: '', fecha_nacimiento: '', sexo: '', telefono: '', correo_contacto: '', direccion: '', 'contacto_emergencia.nombre': '', 'contacto_emergencia.parentesco': '', 'contacto_emergencia.telefono': '' };
const props = { draft, onChange: vi.fn(), emergency: false, onEmergencyChange: vi.fn() };

it('la presentación administrativa es opcional: el perfil conserva sus campos y no recibe ayudas del piloto', () => {
  render(<PatientForm {...props} contactOnly />);
  expect(screen.queryByRole('group', { name: 'Identidad del paciente' })).not.toBeInTheDocument();
  expect(screen.queryByText(/Los datos de la persona|Comprueba los datos de la persona/)).not.toBeInTheDocument();
  expect(screen.getByLabelText('Teléfono')).toBeRequired();
  expect(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' })).not.toHaveAttribute('aria-describedby');
});

it('el formulario administrativo conserva etiquetas, errores asociados y bloqueo de todos los campos al guardar', () => {
  render(<PatientForm {...props} administrative busy emergency fields={{ telefono: 'Revisa el teléfono.', direccion: 'Revisa la dirección.' }} />);
  expect(screen.getByLabelText('Teléfono')).toHaveAccessibleDescription('Revisa el teléfono.');
  expect(screen.getByLabelText('Dirección (opcional)')).toHaveAccessibleDescription('Revisa la dirección.');
  for (const label of ['CI (opcional)', 'Nombres', 'Teléfono', 'Nombre del contacto', 'Dirección (opcional)']) expect(screen.getByLabelText(label)).toBeDisabled();
  expect(screen.getByRole('checkbox', { name: 'Registrar contacto de emergencia' })).toBeDisabled();
});
