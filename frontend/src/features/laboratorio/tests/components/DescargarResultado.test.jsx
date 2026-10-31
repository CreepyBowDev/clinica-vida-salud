import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StrictMode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { avanzarSesion } from '../../../../api/api';
import DescargarResultado from '../../components/DescargarResultado';
import { obtenerArchivo } from '../../services/laboratorio.service';
import { iniciarDescarga } from '../../utils/descargaLaboratorio';
vi.mock('../../services/laboratorio.service', () => ({ obtenerArchivo: vi.fn() }));
vi.mock('../../utils/descargaLaboratorio', () => ({ iniciarDescarga: vi.fn() }));
beforeEach(() => { vi.clearAllMocks(); obtenerArchivo.mockResolvedValue({ blob: new Blob(['ficticio']), nombre: 'resultado-81.pdf' }); iniciarDescarga.mockReturnValue(vi.fn()); });
const mount = reload => render(<DescargarResultado solicitud="71" version="81" resultado={null} onReload={reload ?? vi.fn()} />);
it('doble clic inicia solo una descarga; desmontar retira URL', async () => {
  let resolve; obtenerArchivo.mockImplementation(() => new Promise(r => { resolve = r; })); const release = vi.fn(); iniciarDescarga.mockReturnValue(release);
  const { unmount } = mount(); const user = userEvent.setup(); await user.dblClick(screen.getByRole('button', { name: 'Descargar resultado' }));
  expect(obtenerArchivo).toHaveBeenCalledTimes(1); expect(screen.getByRole('button', { name: 'Descargando…' })).toBeDisabled();
  await act(async () => resolve({ blob: new Blob(), nombre: 'resultado-81.pdf' })); expect(screen.getByRole('status')).toHaveTextContent('Descarga iniciada');
  unmount(); expect(release).toHaveBeenCalledTimes(1);
});
it.each(['sesion', 'pagehide', 'desmontaje'])('retira operación pendiente por %s sin iniciar archivo tardío', async caso => {
  let resolve; obtenerArchivo.mockImplementation(() => new Promise(r => { resolve = r; })); const { unmount } = mount();
  await userEvent.click(screen.getByRole('button', { name: 'Descargar resultado' })); const signal = obtenerArchivo.mock.calls[0][1];
  act(() => { if (caso === 'sesion') avanzarSesion(); else if (caso === 'pagehide') window.dispatchEvent(new Event('pagehide')); else unmount(); });
  expect(signal.aborted).toBe(true); await act(async () => resolve({ blob: new Blob(), nombre: 'resultado-81.pdf' })); expect(iniciarDescarga).not.toHaveBeenCalled();
});
it('409 obliga reconsulta sin repetir descarga ni mostrar cuerpo técnico', async () => {
  obtenerArchivo.mockRejectedValue({ response: { status: 409, data: { error: { message: 'PRIVADO' } } } }); const reload = vi.fn(); mount(reload);
  await userEvent.click(screen.getByRole('button', { name: 'Descargar resultado' })); await waitFor(() => expect(screen.getByRole('alert')).toBeVisible());
  expect(screen.queryByText('PRIVADO')).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Descargar resultado' })).toBeDisabled();
  await userEvent.click(screen.getByRole('button', { name: 'Volver a consultar la solicitud' })); expect(reload).toHaveBeenCalledTimes(1); expect(obtenerArchivo).toHaveBeenCalledTimes(1);
});
it('429 respeta espera sin descarga o consulta automática', async () => {
  obtenerArchivo.mockRejectedValue({ response: { status: 429, headers: { 'retry-after': '60' } } }); mount();
  await userEvent.click(screen.getByRole('button', { name: 'Descargar resultado' })); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Volver a consultar la solicitud' })).toBeDisabled(); expect(obtenerArchivo).toHaveBeenCalledTimes(1);
});
it('StrictMode puede descargar después del ciclo inicial de montaje y limpieza', async () => {
  render(<StrictMode><DescargarResultado solicitud="71" version="81" resultado={null} onReload={vi.fn()} /></StrictMode>);
  await userEvent.click(screen.getByRole('button', { name: 'Descargar resultado' }));
  await screen.findByRole('status'); expect(obtenerArchivo).toHaveBeenCalledTimes(1); expect(iniciarDescarga).toHaveBeenCalledTimes(1);
});
