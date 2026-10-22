import { createRef } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import FormSection from './FormSection';
import FormField from './FormField';
import ConfirmationPanel from './ConfirmationPanel';
import PageHeading from './PageHeading';

it('la sección asocia legend y campos sin alterar sus valores o eventos', async () => {
  const change = vi.fn(); render(<FormSection title="Datos de contacto"><FormField label="Teléfono" defaultValue="70000000" onChange={change} /></FormSection>);
  const group = screen.getByRole('group', { name: 'Datos de contacto' });
  expect(within(group).getByLabelText('Teléfono')).toHaveValue('70000000');
  await userEvent.type(screen.getByLabelText('Teléfono'), '1'); expect(change).toHaveBeenCalledTimes(1);
});
it('disabled congela los campos de la sección sin bloquear controles externos', async () => {
  const click = vi.fn(); render(<><FormSection title="Datos" disabled><FormField label="Nombre" defaultValue="Persona" /><button onClick={click}>Cambiar</button></FormSection><button>Volver a revisar</button></>);
  expect(screen.getByLabelText('Nombre')).toBeDisabled(); await userEvent.click(screen.getByRole('button', { name: 'Cambiar' })); expect(click).not.toHaveBeenCalled();
  expect(screen.getByRole('button', { name: 'Volver a revisar' })).toBeEnabled();
});
it('la confirmación es una región con nombre, no un modal ni un envío automático', () => {
  render(<ConfirmationPanel title="Revisar cambio"><p>Se conserva el historial.</p></ConfirmationPanel>);
  expect(screen.getByRole('region', { name: 'Revisar cambio' })).toHaveTextContent('Se conserva el historial.');
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument(); expect(screen.queryByRole('button')).not.toBeInTheDocument();
});
it('dos confirmaciones generan etiquetas únicas y conservan anuncios de su consumidor', () => {
  render(<><ConfirmationPanel title="Primera"><p role="status">Revisa los datos.</p></ConfirmationPanel><ConfirmationPanel title="Segunda"><p>Otra operación.</p></ConfirmationPanel></>);
  const regions = screen.getAllByRole('region'); expect(regions[0].getAttribute('aria-labelledby')).not.toBe(regions[1].getAttribute('aria-labelledby'));
  expect(screen.getByRole('status')).toHaveTextContent('Revisa los datos.');
});
it('PageHeading conserva la referencia de foco al mostrar un resultado confirmado', () => {
  const ref = createRef(); const { rerender } = render(<PageHeading title="Registrar" titleRef={ref} />);
  expect(ref.current).toHaveAttribute('tabindex', '-1'); rerender(<PageHeading title="Registro creado" titleRef={ref} />);
  ref.current.focus(); expect(screen.getByRole('heading', { name: 'Registro creado', level: 1 })).toHaveFocus();
});
