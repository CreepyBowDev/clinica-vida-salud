import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { avanzarSesion } from '../../../../api/api';
import DescargarReportePdf from '../../components/DescargarReportePdf';
import { descargarReportePdf } from '../../services/reportePdf.service';

vi.mock('../../services/reportePdf.service', () => ({ descargarReportePdf: vi.fn() }));
let click, create, revoke;
const archivo = () => ({ blob: new Blob(['PDF ficticio']), nombre: 'reporte-citas-programadas.pdf' });
beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('URL', class extends URL {
    static createObjectURL = vi.fn(() => 'blob:prueba');
    static revokeObjectURL = vi.fn();
  });
  create = URL.createObjectURL; revoke = URL.revokeObjectURL;
  click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  descargarReportePdf.mockResolvedValue(archivo());
});
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); });
const mount = () => render(<DescargarReportePdf tipo="programadas" filtros={{ fecha: '2026-11-07', page: 2 }} />);
it('teclado activa descarga, conserva foco, nombre y revoca URL tras consumo', async () => {
  mount(); const button = screen.getByRole('button', { name: 'Descargar PDF' });
  button.focus(); await userEvent.keyboard('{Enter}'); await waitFor(() => expect(click).toHaveBeenCalledOnce());
  expect(button).toHaveFocus(); expect(click.mock.instances[0].download).toBe('reporte-citas-programadas.pdf');
  expect(document.querySelector('a[download]')).toBeNull();
  expect(screen.getByText(/nueva lectura del servidor/)).toHaveTextContent('puede diferir');
  await waitFor(() => expect(revoke).toHaveBeenCalledExactlyOnceWith('blob:prueba'), { timeout: 2000 });
});
it('desmontar revoca la URL pendiente sin esperar el temporizador', async () => {
  const view = mount(); await userEvent.click(screen.getByRole('button', { name: 'Descargar PDF' }));
  await waitFor(() => expect(create).toHaveBeenCalledOnce()); view.unmount(); expect(revoke).toHaveBeenCalledOnce();
});
it('carga evita duplicación y cancelar restaura foco y descarta respuesta tardía', async () => {
  let resolve, signal;
  descargarReportePdf.mockImplementation((t, p, s) => new Promise(r => { resolve = r; signal = s; }));
  mount(); const button = screen.getByRole('button', { name: 'Descargar PDF' });
  await userEvent.click(button); expect(button).toBeDisabled(); expect(button).toHaveAttribute('aria-busy', 'true');
  fireEvent.click(button); expect(descargarReportePdf).toHaveBeenCalledOnce();
  await userEvent.click(screen.getByRole('button', { name: 'Cancelar espera' }));
  expect(signal.aborted).toBe(true); expect(button).toBeEnabled(); expect(button).toHaveFocus();
  await act(async () => resolve(archivo())); expect(create).not.toHaveBeenCalled();
});
it.each(['sesion', 'salir', 'pagehide'])('%s aborta y descarta aun si el servicio ignora signal', async mode => {
  let resolve, signal;
  descargarReportePdf.mockImplementation((t, p, s) => new Promise(r => { resolve = r; signal = s; }));
  const view = mount(); await userEvent.click(screen.getByRole('button', { name: 'Descargar PDF' }));
  if (mode === 'sesion') act(() => avanzarSesion());
  else if (mode === 'salir') view.unmount(); else act(() => window.dispatchEvent(new Event('pagehide')));
  expect(signal.aborted).toBe(true); await act(async () => resolve(archivo())); expect(create).not.toHaveBeenCalled();
});
it('429 anuncia espera, bloquea el botón y permite reintento solo tras vencer', async () => {
  descargarReportePdf.mockRejectedValue(Object.assign(new Error('Hay demasiadas solicitudes.'), { retryAfter: 2 }));
  mount(); await userEvent.click(screen.getByRole('button', { name: 'Descargar PDF' })); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Descargar PDF' })).toBeDisabled();
  expect(screen.getByText(/Espera 2 s/)).toHaveAttribute('aria-live', 'polite');
  await waitFor(() => expect(screen.getByRole('button', { name: 'Descargar PDF' })).toBeEnabled(), { timeout: 4000 });
  expect(create).not.toHaveBeenCalled();
});
