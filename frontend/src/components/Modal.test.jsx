import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Modal from './Modal';

let show, close;
beforeEach(() => {
  show = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
  close = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
  Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value() { this.setAttribute('open', ''); this.querySelector('button')?.focus(); } });
  Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value() { this.removeAttribute('open'); } });
});
afterEach(() => {
  cleanup();
  for (const [name, descriptor] of [['showModal', show], ['close', close]]) {
    if (descriptor) Object.defineProperty(HTMLDialogElement.prototype, name, descriptor); else delete HTMLDialogElement.prototype[name];
  }
});
it('abre con nombre accesible, conserva etiqueta predeterminada y restaura foco al cerrar', () => {
  const trigger = document.createElement('button'); document.body.append(trigger); trigger.focus();
  const { rerender, unmount } = render(<Modal open title="Ayuda" onClose={vi.fn()}><p>Instrucciones</p></Modal>);
  expect(screen.getByRole('dialog', { name: 'Ayuda' })).toBeInTheDocument(); expect(document.body).toHaveClass('modal-open');
  expect(screen.getByRole('button', { name: 'Cerrar ayuda' })).toHaveFocus();
  rerender(<Modal open={false} title="Ayuda" onClose={vi.fn()} />);
  expect(document.body).not.toHaveClass('modal-open'); expect(trigger).toHaveFocus(); unmount(); trigger.remove();
});
it('Tab y Shift+Tab incluyen selects/textarea y excluyen controles ocultos o deshabilitados', () => {
  render(<Modal open title="Opciones" onClose={vi.fn()} closeLabel="Cerrar opciones"><select aria-label="Selección"><option>A</option></select><textarea aria-label="Notas" /><button disabled>No disponible</button><div hidden><button>Oculto</button></div></Modal>);
  const first = screen.getByRole('button', { name: 'Cerrar opciones' }), last = screen.getByRole('textbox');
  last.focus(); fireEvent.keyDown(last, { key: 'Tab' }); expect(first).toHaveFocus();
  fireEvent.keyDown(first, { key: 'Tab', shiftKey: true }); expect(last).toHaveFocus();
});
it('cancel nativo y botón solicitan cierre sin cambiar el contrato del padre', () => {
  const onClose = vi.fn(); render(<Modal open title="Ayuda" onClose={onClose} />);
  const event = new Event('cancel', { bubbles: true, cancelable: true }); fireEvent(screen.getByRole('dialog'), event);
  expect(event.defaultPrevented).toBe(true); expect(onClose).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Cerrar ayuda' })); expect(onClose).toHaveBeenCalledTimes(2);
});
