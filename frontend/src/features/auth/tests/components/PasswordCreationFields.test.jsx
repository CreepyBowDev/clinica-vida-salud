import { useState } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PasswordCreationFields from '../../components/PasswordCreationFields';

function Form({ fields, busy = false }) {
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  return <PasswordCreationFields password={password} confirmation={confirmation} fields={fields} busy={busy}
    onPasswordChange={event => setPassword(event.target.value)} onConfirmationChange={event => setConfirmation(event.target.value)} />;
}

describe('ayuda de contraseña nueva', () => {
  it('actualiza los cinco requisitos y vuelve a pendiente al borrar', () => {
    render(<Form />);
    const list = screen.getByRole('list', { name: 'Requisitos de la contraseña' });
    expect(within(list).getAllByText(/Pendiente/)).toHaveLength(5);
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: 'Frase ficticia 123!' } });
    expect(within(list).getAllByText(/Cumplido/)).toHaveLength(5);
    fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: 'Frase ficticia 123 ' } });
    expect(within(list).getAllByText(/Pendiente/)).toHaveLength(1);
    expect(screen.getByLabelText('Nueva contraseña')).toHaveAccessibleDescription(/Un carácter especial: Pendiente/);
  });

  it('muestra coincidencia en vivo, también si cambia la primera clave', () => {
    render(<Form />);
    const password = screen.getByLabelText('Nueva contraseña');
    const confirmation = screen.getByLabelText('Confirmar nueva contraseña');
    fireEvent.change(password, { target: { value: 'Frase ficticia 123!' } });
    fireEvent.change(confirmation, { target: { value: 'otra' } });
    expect(confirmation).toHaveAccessibleDescription('La confirmación de la contraseña no coincide.');
    expect(confirmation).toHaveAttribute('aria-invalid', 'true');
    fireEvent.change(confirmation, { target: { value: 'Frase ficticia 123!' } });
    expect(confirmation).toHaveAccessibleDescription('Las contraseñas coinciden.');
    fireEvent.change(password, { target: { value: 'Frase ficticia 123€' } });
    expect(confirmation).toHaveAttribute('aria-invalid', 'true');
  });

  it('conserva errores del servidor, bloqueo de envío y mostrar/ocultar', () => {
    render(<Form busy fields={{ password: 'La contraseña no es válida.' }} />);
    expect(screen.getByLabelText('Nueva contraseña')).toHaveAccessibleDescription(/La contraseña no es válida\./);
    expect(screen.getByLabelText('Nueva contraseña')).toHaveAttribute('readonly');
    expect(screen.getByRole('button', { name: 'Mostrar nueva contraseña' })).toBeDisabled();
  });

  it('genera descripciones únicas sin exponer la contraseña en la ayuda', () => {
    render(<><Form /><Form /></>);
    const inputs = screen.getAllByLabelText('Nueva contraseña');
    expect(inputs[0].getAttribute('aria-describedby')).not.toBe(inputs[1].getAttribute('aria-describedby'));
    fireEvent.change(inputs[0], { target: { value: 'Frase ficticia 123!' } });
    expect(document.getElementById(inputs[0].getAttribute('aria-describedby'))).not.toHaveTextContent('Frase ficticia 123!');
  });
});
