import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { useGestionCita } from '../../hooks/useGestionCita';
import { cancelarCita, consultarGestionCita, reprogramarCita } from '../../services/gestionCita.service';
import { alternativas, cancelada, cita, gestion, reprogramada } from '../utils/gestionFixtures';

vi.mock('../../services/gestionCita.service', () => ({ cancelarCita: vi.fn(), consultarGestionCita: vi.fn(), reprogramarCita: vi.fn() }));
const entrada = () => ({ vista: alternativas().data, turno: alternativas().data.turnos[0] });
beforeEach(() => { vi.resetAllMocks(); cancelarCita.mockResolvedValue(cancelada()); reprogramarCita.mockResolvedValue(reprogramada()); consultarGestionCita.mockResolvedValue(gestion()); });

it('confirma una sola vez por revisión aunque dos llamadas lleguen antes de renderizar', async () => {
  let complete; reprogramarCita.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  let first;
  act(() => { first = result.current.guardar(entrada()); result.current.guardar(entrada()); });
  expect(reprogramarCita).toHaveBeenCalledTimes(1); expect(result.current.peligro).toBe(true);
  act(() => result.current.reset()); expect(result.current.status).toBe('guardando');
  await act(async () => { complete(reprogramada()); await first; }); expect(result.current.status).toBe('guardada');
  await act(async () => result.current.guardar(entrada())); expect(reprogramarCita).toHaveBeenCalledTimes(1);
});
it('red tras envío impide reset/otro PATCH hasta consulta explícita, incluso si estado actual no cambió', async () => {
  reprogramarCita.mockRejectedValue({ gestionEnviada: true });
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  await act(async () => result.current.guardar(entrada())); expect(result.current.status).toBe('incierto');
  act(() => result.current.reset()); await act(async () => result.current.guardar(entrada())); expect(result.current.status).toBe('incierto');
  expect(reprogramarCita).toHaveBeenCalledTimes(1); expect(consultarGestionCita).not.toHaveBeenCalled();
  await act(async () => result.current.consultar()); expect(result.current.status).toBe('recuperada');
  expect(result.current.resultado.version_programacion).toBe(1); expect(result.current.peligro).toBe(false);
  act(() => result.current.reset()); expect(result.current.status).toBe('inicial');
});
it('fallo de recuperación conserva incertidumbre y no habilita repetición', async () => {
  reprogramarCita.mockRejectedValue({ gestionEnviada: true }); consultarGestionCita.mockRejectedValue({ response: { status: 500 } });
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  await act(async () => result.current.guardar(entrada())); await act(async () => result.current.consultar());
  expect(result.current.status).toBe('incierto'); expect(result.current.failure.message).toContain('podría haberse guardado');
  expect(reprogramarCita).toHaveBeenCalledTimes(1);
});
it.each([419, 422, 409, 403, 404])('rechazo conocido %s permite nueva revisión explícita, no reintento automático', async status => {
  reprogramarCita.mockRejectedValue({ gestionEnviada: true, response: { status } });
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  await act(async () => result.current.guardar(entrada())); expect(result.current.status).toBe('error'); expect(result.current.peligro).toBe(false);
  expect(consultarGestionCita).not.toHaveBeenCalled(); expect(reprogramarCita).toHaveBeenCalledTimes(1);
});
it('error local previo al envío no se transforma en resultado incierto', async () => {
  reprogramarCita.mockRejectedValue(new Error('VALIDACION-LOCAL'));
  const { result } = renderHook(() => useGestionCita(cita.id_cita)); await act(async () => result.current.guardar(entrada()));
  expect(result.current.status).toBe('error'); expect(result.current.failure.message).not.toContain('VALIDACION-LOCAL');
});
it('pagehide en vuelo limpia datos, conserva necesidad de comprobar y descarta respuesta tardía', async () => {
  let complete; reprogramarCita.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  let pending; act(() => { pending = result.current.guardar(entrada()); });
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(result.current.status).toBe('incierto'); expect(result.current.resultado).toBeNull();
  await act(async () => { complete(reprogramada()); await pending; }); expect(result.current.status).toBe('incierto');
  expect(result.current.resultado).toBeNull(); await act(async () => result.current.consultar()); expect(result.current.status).toBe('recuperada');
});
it('desmontaje no publica éxito ni invita a reenviar cuando termina un PATCH pendiente', async () => {
  let complete; reprogramarCita.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result, unmount } = renderHook(() => useGestionCita(cita.id_cita));
  let pending; act(() => { pending = result.current.guardar(entrada()); }); unmount();
  complete(reprogramada()); expect(await pending).toBe(false);
});
it('beforeunload advierte solo durante incertidumbre/guardado', async () => {
  reprogramarCita.mockRejectedValue({ gestionEnviada: true });
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  let event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); expect(event.defaultPrevented).toBe(false);
  await act(async () => result.current.guardar(entrada()));
  event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
  await act(async () => result.current.consultar());
  event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); expect(event.defaultPrevented).toBe(false);
});
it('429 conserva bloqueo temporal sin auto-reintentar ni omitirlo al resetear', async () => {
  reprogramarCita.mockRejectedValue({ gestionEnviada: true, response: { status: 429, headers: { 'retry-after': '1' } } });
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  await act(async () => result.current.guardar(entrada())); expect(result.current.remaining).toBe(1);
  act(() => result.current.reset()); expect(result.current.status).toBe('error');
  await waitFor(() => expect(result.current.remaining).toBe(0), { timeout: 2500 }); expect(reprogramarCita).toHaveBeenCalledTimes(1);
  act(() => result.current.reset()); expect(result.current.status).toBe('inicial');
});

it.each(['cancelar', 'guardar'])('%s bloquea ambos tipos de escritura hasta conocer el resultado', async tipo => {
  let complete;
  const service = tipo === 'cancelar' ? cancelarCita : reprogramarCita;
  service.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  let pending; act(() => {
    pending = result.current[tipo](tipo === 'cancelar' ? { original: gestion(), motivo: 'PRIVADO' } : entrada());
    result.current.cancelar({ original: gestion() }); result.current.guardar(entrada());
  });
  expect(service).toHaveBeenCalledTimes(1); expect(tipo === 'cancelar' ? reprogramarCita : cancelarCita).not.toHaveBeenCalled();
  await act(async () => { complete(tipo === 'cancelar' ? cancelada() : reprogramada()); await pending; });
  expect(result.current.operacion).toBe(tipo === 'cancelar' ? 'CANCELACION' : 'REPROGRAMACION');
});
it('cancelación incierta conserva operación al recuperar y no retiene motivo en estado', async () => {
  cancelarCita.mockRejectedValue({ gestionEnviada: true });
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  await act(async () => result.current.cancelar({ original: gestion(), motivo: 'MOTIVO-PRIVADO' }));
  expect(result.current.status).toBe('incierto'); expect(result.current.failure.message).toContain('La cancelación podría');
  expect(JSON.stringify(result.current)).not.toContain('MOTIVO-PRIVADO');
  await act(async () => result.current.guardar(entrada())); expect(reprogramarCita).not.toHaveBeenCalled();
  await act(async () => result.current.consultar()); expect(result.current.status).toBe('recuperada'); expect(result.current.operacion).toBe('CANCELACION');
  expect(cancelarCita).toHaveBeenCalledTimes(1);
});
it('cancelación en vuelo y pagehide conserva solo necesidad de comprobación, no motivo/resultado', async () => {
  let complete; cancelarCita.mockImplementation(() => new Promise(resolve => { complete = resolve; }));
  const { result } = renderHook(() => useGestionCita(cita.id_cita));
  let pending; act(() => { pending = result.current.cancelar({ original: gestion(), motivo: 'MOTIVO-PRIVADO' }); });
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(result.current.operacion).toBe('CANCELACION'); expect(result.current.status).toBe('incierto');
  expect(JSON.stringify(result.current)).not.toContain('MOTIVO-PRIVADO');
  await act(async () => { complete(cancelada()); await pending; }); expect(result.current.status).toBe('incierto'); expect(result.current.resultado).toBeNull();
});
