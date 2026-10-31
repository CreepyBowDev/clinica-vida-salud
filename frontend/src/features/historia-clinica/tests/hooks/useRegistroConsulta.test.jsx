import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { registrarConsulta, actualizarConsulta } from '../../services/consultaMedica.service';
import { consultarConsulta } from '../../services/historiaClinica.service';
import { useRegistroConsulta } from '../../hooks/useRegistroConsulta';
vi.mock('../../services/consultaMedica.service', async importOriginal => ({ ...await importOriginal(), registrarConsulta: vi.fn(), actualizarConsulta: vi.fn() }));
vi.mock('../../services/historiaClinica.service', () => ({ consultarConsulta: vi.fn() }));
beforeEach(() => vi.resetAllMocks()); afterEach(() => vi.useRealTimers());
const mount = () => renderHook(() => useRegistroConsulta('41', 'Médico', '7'));
const failure = (status, consultaEnviada = true, code) => ({ consultaEnviada, response: status ? { status, headers: { 'retry-after': '2' }, data: { error: { code, message: 'NO-MOSTRAR', details: { 'consulta.motivo_consulta': ['Indica el motivo.'] } } } } : undefined });
it('lock síncrono impide doble POST y guardado confirmado no reenvía', async () => {
  let resolve; registrarConsulta.mockImplementation(() => new Promise(r => { resolve = r; })); const view = mount();
  let p; act(() => { p = view.result.current.guardar({ declaracion: true }); view.result.current.guardar({ declaracion: true }); });
  expect(registrarConsulta).toHaveBeenCalledOnce(); await act(async () => { resolve({ consulta: {} }); await p; });
  expect(view.result.current.status).toBe('guardada'); await act(async () => view.result.current.guardar({})); expect(registrarConsulta).toHaveBeenCalledOnce();
});
it.each([undefined, 500, 502])('fallo %s después de envío bloquea replay y reconcilia solo GET', async status => {
  registrarConsulta.mockRejectedValue(failure(status)); const view = mount(); await act(async () => view.result.current.guardar({}));
  expect(view.result.current.status).toBe('incierto'); act(() => view.result.current.reset()); await act(async () => view.result.current.guardar({})); expect(registrarConsulta).toHaveBeenCalledOnce();
  consultarConsulta.mockResolvedValue({ consulta: null }); await act(async () => view.result.current.consultar());
  expect(view.result.current.status).toBe('recuperada'); expect(consultarConsulta).toHaveBeenCalledWith({ id: '41', rol: 'Médico' }, expect.any(AbortSignal));
});
it.each([409, 419, 403, 404])('rechazo conocido %s no reenvía y exige nuevo contexto', async status => {
  registrarConsulta.mockRejectedValue(failure(status, true, 'CLINICA_EDICION_OBSOLETA')); const view = mount(); await act(async () => view.result.current.guardar({}));
  expect(view.result.current.status).toBe('error'); expect(view.result.current.failure.message).not.toContain('NO-MOSTRAR');
  await act(async () => view.result.current.guardar({})); expect(registrarConsulta).toHaveBeenCalledOnce();
});
it('422 permite corregir solo campos reconocidos sin replay automático', async () => {
  registrarConsulta.mockRejectedValue(failure(422)); const view = mount(); await act(async () => view.result.current.guardar({}));
  expect(view.result.current.status).toBe('validacion'); expect(view.result.current.failure.fields).toEqual({ 'consulta.motivo_consulta': 'Indica el motivo.' });
});
it('CSRF previo fallido no se presenta como guardado', async () => {
  registrarConsulta.mockRejectedValue(failure(undefined, false)); const view = mount(); await act(async () => view.result.current.guardar({}));
  expect(view.result.current.status).toBe('error'); expect(view.result.current.failure.message).toContain('No se envió');
});
it('pagehide durante POST preserva solo riesgo, aborta y descarta resolución tardía', async () => {
  let resolve; registrarConsulta.mockImplementation(() => new Promise(r => { resolve = r; })); const view = mount();
  let p; act(() => { p = view.result.current.guardar({}); }); const signal = registrarConsulta.mock.calls[0][1];
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true); expect(view.result.current.status).toBe('incierto');
  await act(async () => { resolve({ consulta: { diagnostico: 'NO-MOSTRAR' } }); await p; }); expect(view.result.current.resultado).toBeNull();
});
it('429 respeta espera incluso tras reset y no consulta automáticamente', async () => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] }); registrarConsulta.mockRejectedValue(failure(429)); const view = mount();
  await act(async () => view.result.current.guardar({})); expect(view.result.current.remaining).toBe(2); act(() => view.result.current.reset()); expect(view.result.current.status).toBe('error');
  await act(async () => vi.advanceTimersByTime(2000)); act(() => view.result.current.reset()); expect(view.result.current.status).toBe('inicial'); expect(registrarConsulta).toHaveBeenCalledOnce();
});
it('corrección usa PATCH coordinado y no-op conserva resultado explícito sin registrar alta', async () => {
  actualizarConsulta.mockResolvedValue({ consulta: { id_consulta: '31' }, modificado: false }); const view = mount();
  await act(async () => view.result.current.guardar({ contexto: { consulta: { id_consulta: '31' } } }));
  expect(actualizarConsulta).toHaveBeenCalledOnce(); expect(registrarConsulta).not.toHaveBeenCalled(); expect(view.result.current.resultado.modificado).toBe(false);
});
it.each([409, 419, 422, 429])('corrección con rechazo %s no hace replay ni cambia a alta', async status => {
  actualizarConsulta.mockRejectedValue(failure(status, true, 'CLINICA_EDICION_OBSOLETA')); const view = mount();
  await act(async () => view.result.current.guardar({ contexto: { consulta: { id_consulta: '31' } } }));
  expect(registrarConsulta).not.toHaveBeenCalled(); expect(actualizarConsulta).toHaveBeenCalledOnce(); expect(view.result.current.status).not.toBe('guardada');
});
it('corrección incierta con GET fallido sigue bloqueando todas las escrituras', async () => {
  actualizarConsulta.mockRejectedValue(failure(500)); consultarConsulta.mockRejectedValue(failure(500)); const view = mount();
  await act(async () => view.result.current.guardar({ contexto: { consulta: { id_consulta: '31' } } })); await act(async () => view.result.current.consultar());
  expect(view.result.current.status).toBe('incierto'); act(() => view.result.current.reset()); await act(async () => view.result.current.guardar({}));
  expect(actualizarConsulta).toHaveBeenCalledOnce(); expect(registrarConsulta).not.toHaveBeenCalled();
});
