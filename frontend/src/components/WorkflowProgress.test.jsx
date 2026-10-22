import { render, screen, within } from '@testing-library/react';
import { expect, it } from 'vitest';
import WorkflowProgress from './WorkflowProgress';
import ConfirmationPanel from './ConfirmationPanel';
import { PLANNING_STEPS } from '../constants/workflows';

it('presenta etapas en orden sin enlaces, botones o saltos de validación', () => {
  render(<WorkflowProgress label="Etapas" steps={PLANNING_STEPS} current="propuesta" />);
  const list = screen.getByRole('list', { name: 'Etapas' }); expect(within(list).getAllByRole('listitem')).toHaveLength(3);
  expect(list.querySelector('[aria-current="step"]')).toHaveTextContent('Preparar propuesta');
  expect(within(list).queryByRole('link')).not.toBeInTheDocument(); expect(within(list).queryByRole('button')).not.toBeInTheDocument();
});
it('actualiza solo la etapa actual, sin declarar el guardado como completado', () => {
  const { rerender } = render(<WorkflowProgress label="Etapas" steps={PLANNING_STEPS} current="impacto" />);
  rerender(<WorkflowProgress label="Etapas" steps={PLANNING_STEPS} current="confirmacion" />);
  const list = screen.getByRole('list'); expect(list.querySelectorAll('[aria-current="step"]')).toHaveLength(1);
  expect(list.querySelector('[aria-current="step"]')).toHaveTextContent('Confirmar guardado'); expect(list).not.toHaveTextContent(/guardado completo|éxito/i);
});
it('no inventa una etapa activa cuando no coincide con la recibida', () => {
  render(<WorkflowProgress label="Etapas" steps={PLANNING_STEPS} current="desconocida" />);
  expect(screen.getByRole('list').querySelector('[aria-current]')).toBeNull();
});
it('el panel conserva el nombre accesible existente de una confirmación de planificación', () => {
  render(<ConfirmationPanel label="Confirmación de horario" title="Confirmar propuesta de horario"><p>Revisa la propuesta completa.</p></ConfirmationPanel>);
  expect(screen.getByRole('region', { name: 'Confirmación de horario' })).toHaveTextContent('Revisa la propuesta completa.');
  expect(screen.getByRole('heading', { name: 'Confirmar propuesta de horario' })).toBeInTheDocument();
});
