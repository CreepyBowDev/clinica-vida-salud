import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useAsistenciaCita } from '../../hooks/useAsistenciaCita';
import { confirmarLlegada, consultarAsistenciaCita, registrarInasistencia, marcarAtendida } from '../../services/asistenciaCita.service';
import { asistencia, confirmada, llegada, ausenciaElegible, inasistencia, ausente, atencion, atendida } from '../utils/asistenciaFixtures';

vi.mock('../../services/asistenciaCita.service', () => ({ confirmarLlegada: vi.fn(), consultarAsistenciaCita: vi.fn(), registrarInasistencia: vi.fn(), marcarAtendida: vi.fn() }));
beforeEach(() => { vi.resetAllMocks(); confirmarLlegada.mockResolvedValue(llegada()); consultarAsistenciaCita.mockResolvedValue(confirmada()); registrarInasistencia.mockResolvedValue(inasistencia()); });
afterEach(() => vi.useRealTimers());
const mount = () => renderHook(() => useAsistenciaCita(asistencia().id_cita, 'Administrador', '1'));
const datos = { original: asistencia(), declaracion: true };

it.each(['guardar', 'registrarInasistencia', 'marcarAtendida'])('lock único impide %s durante atención y doble clic', async otra => {
  marcarAtendida.mockResolvedValue(atencion()); const { result } = mount();
  await act(async () => { await Promise.all([result.current.marcarAtendida(datos), result.current[otra](datos)]); });
  expect(marcarAtendida).toHaveBeenCalledTimes(1); expect(confirmarLlegada).not.toHaveBeenCalled(); expect(registrarInasistencia).not.toHaveBeenCalled();
  expect(result.current.operacion).toBe('ATENCION'); expect(result.current.status).toBe('guardada');
});
it.each([undefined, 408, 500, 502])('atención con fallo %s exige GET explícito y mantiene operación durante recuperación', async status => {
  marcarAtendida.mockRejectedValue({ asistenciaEnviada: true, response: { status, data: {} } }); consultarAsistenciaCita.mockResolvedValue(atendida());
  const { result } = mount(); await act(async () => { await result.current.marcarAtendida(datos); });
  expect(result.current.status).toBe('incierto'); expect(result.current.failure.message).toContain('atención podría haberse guardado');
  act(() => result.current.reset()); await act(async () => { await result.current.guardar(datos); await result.current.registrarInasistencia(datos); });
  expect(confirmarLlegada).not.toHaveBeenCalled(); expect(registrarInasistencia).not.toHaveBeenCalled();
  await act(async () => { await result.current.consultar(); }); expect(result.current.status).toBe('recuperada'); expect(result.current.operacion).toBe('ATENCION');
  expect(marcarAtendida).toHaveBeenCalledTimes(1);
});
it('422 solo admite declaración de atención y 409 anticipada se traduce sin contenido técnico', async () => {
  marcarAtendida.mockRejectedValue({ asistenciaEnviada: true, response: { status: 422, data: { error: { details: {
    atencion_realizada: ['Comprueba atención'], ausencia_verificada: ['NO-SALIR'], presencia_verificada: ['NO-SALIR'] } } } } });
  const { result } = mount(); await act(async () => { await result.current.marcarAtendida(datos); });
  expect(result.current.failure.fields).toEqual({ atencion_realizada: 'Comprueba atención' });
  act(() => result.current.reset()); marcarAtendida.mockRejectedValue({ asistenciaEnviada: true,
    response: { status: 409, data: { error: { code: 'CITA_ATENCION_ANTICIPADA', message: 'SQL' } } } });
  await act(async () => { await result.current.marcarAtendida(datos); }); expect(result.current.failure.message).toContain('no ha alcanzado su inicio');
});
it('pagehide mantiene atención incierta y descarta respuesta tardía', async () => {
  let complete; marcarAtendida.mockImplementation(() => new Promise(resolve => { complete = resolve; })); const { result } = mount();
  let pendiente; act(() => { pendiente = result.current.marcarAtendida(datos); });
  const signal = marcarAtendida.mock.calls[0][1]; act(() => window.dispatchEvent(new Event('pagehide')));
  expect(signal.aborted).toBe(true); expect(result.current.status).toBe('incierto'); expect(result.current.operacion).toBe('ATENCION');
  await act(async () => { complete(atencion()); await pendiente; }); expect(result.current.status).toBe('incierto');
});

it('lock inmediato impide doble envío y exige nueva revisión después de éxito', async () => {
  const { result } = mount();
  await act(async () => { await Promise.all([result.current.guardar(datos), result.current.guardar(datos)]); });
  expect(confirmarLlegada).toHaveBeenCalledTimes(1); expect(result.current.status).toBe('guardada');
  await act(async () => { await result.current.guardar(datos); }); expect(confirmarLlegada).toHaveBeenCalledTimes(1);
});
it.each([undefined, 408, 500, 502])('fallo %s tras PATCH bloquea reset/reenvío y solo GET reconcilia', async status => {
  confirmarLlegada.mockRejectedValue({ asistenciaEnviada: true, ...(status ? { response: { status, data: {} } } : {}) });
  const { result } = mount(); await act(async () => { await result.current.guardar(datos); });
  expect(result.current.status).toBe('incierto'); expect(result.current.peligro).toBe(true);
  act(() => result.current.reset()); await act(async () => { await result.current.guardar(datos); }); expect(confirmarLlegada).toHaveBeenCalledTimes(1);
  await act(async () => { await result.current.consultar(); }); expect(result.current.status).toBe('recuperada');
  expect(consultarAsistenciaCita).toHaveBeenCalledWith({ id: asistencia().id_cita, rol: 'Administrador' }, expect.any(AbortSignal));
});
it.each([401, 403, 404, 409, 419, 422, 429])('%s explícito es rechazo sin replay', async status => {
  confirmarLlegada.mockRejectedValue({ asistenciaEnviada: true, response: { status, data: { error: { code: 'SQL-PRIVADO', message: 'SQL-PRIVADO' } } } });
  const { result } = mount(); await act(async () => { await result.current.guardar(datos); });
  expect(result.current.status).toBe('error'); expect(result.current.failure.message).not.toContain('SQL-PRIVADO');
  expect(confirmarLlegada).toHaveBeenCalledTimes(1); expect(consultarAsistenciaCita).not.toHaveBeenCalled();
});
it('CSRF previo fallido no se confunde con PATCH enviado', async () => {
  confirmarLlegada.mockRejectedValue({ asistenciaEnviada: false }); const { result } = mount();
  await act(async () => { await result.current.guardar(datos); }); expect(result.current.status).toBe('error'); expect(result.current.failure.message).toContain('No se envió');
});
it('recuperación fallida mantiene incertidumbre', async () => {
  confirmarLlegada.mockRejectedValue({ asistenciaEnviada: true }); consultarAsistenciaCita.mockRejectedValue({ response: { status: 500 } });
  const { result } = mount(); await act(async () => { await result.current.guardar(datos); await result.current.consultar(); });
  expect(result.current.status).toBe('incierto'); expect(confirmarLlegada).toHaveBeenCalledTimes(1);
});
it('pagehide aborta, limpia y descarta respuesta tardía sin afirmar rollback', async () => {
  let complete; confirmarLlegada.mockImplementation(() => new Promise(resolve => { complete = resolve; })); const { result } = mount();
  let escritura; act(() => { escritura = result.current.guardar(datos); }); const signal = confirmarLlegada.mock.calls[0][1];
  act(() => window.dispatchEvent(new Event('pagehide'))); expect(signal.aborted).toBe(true); expect(result.current.status).toBe('incierto');
  await act(async () => { complete(llegada()); await escritura; }); expect(result.current.status).toBe('incierto'); expect(result.current.resultado).toBeNull();
});
it('desmontaje aborta escritura y respuesta tardía no continúa', async () => {
  let complete; confirmarLlegada.mockImplementation(() => new Promise(resolve => { complete = resolve; })); const { result, unmount } = mount();
  let escritura; act(() => { escritura = result.current.guardar(datos); }); const signal = confirmarLlegada.mock.calls[0][1]; unmount();
  expect(signal.aborted).toBe(true); await act(async () => { complete(llegada()); expect(await escritura).toBe(false); });
});
it('429 conserva espera y no envía ni consulta automáticamente al vencer', async () => {
  vi.useFakeTimers(); confirmarLlegada.mockRejectedValue({ asistenciaEnviada: true, response: { status: 429, headers: { 'retry-after': '2' } } });
  const { result } = mount(); await act(async () => { await result.current.guardar(datos); }); expect(result.current.remaining).toBe(2);
  act(() => result.current.reset()); expect(result.current.status).toBe('error');
  act(() => vi.advanceTimersByTime(2000)); expect(result.current.remaining).toBe(0); expect(confirmarLlegada).toHaveBeenCalledTimes(1);
});

it.each([true, false])('un solo lock coordina llegada/inasistencia (ausencia primero: %s)', async primeroAusencia => {
  const { result } = mount();
  await act(async () => { await Promise.all(primeroAusencia
    ? [result.current.registrarInasistencia({ original: ausenciaElegible(), declaracion: true }), result.current.guardar(datos)]
    : [result.current.guardar(datos), result.current.registrarInasistencia({ original: ausenciaElegible(), declaracion: true })]); });
  expect(registrarInasistencia).toHaveBeenCalledTimes(primeroAusencia ? 1 : 0); expect(confirmarLlegada).toHaveBeenCalledTimes(primeroAusencia ? 0 : 1);
  expect(result.current.operacion).toBe(primeroAusencia ? 'INASISTENCIA' : 'LLEGADA');
});
it.each([undefined, 408, 500, 502])('ausencia con fallo %s tras envío bloquea ambas acciones hasta GET explícito', async status => {
  registrarInasistencia.mockRejectedValue({ asistenciaEnviada: true, ...(status ? { response: { status, data: {} } } : {}) });
  consultarAsistenciaCita.mockResolvedValue(ausente()); const { result } = mount();
  await act(async () => { await result.current.registrarInasistencia({ original: ausenciaElegible(), declaracion: true }); });
  expect(result.current.status).toBe('incierto'); expect(result.current.failure.message).toContain('inasistencia podría haberse guardado');
  act(() => result.current.reset()); await act(async () => { await result.current.guardar(datos); }); expect(confirmarLlegada).not.toHaveBeenCalled();
  await act(async () => { await result.current.consultar(); }); expect(result.current.status).toBe('recuperada');
  expect(result.current.operacion).toBe('INASISTENCIA'); expect(result.current.resultado.estado).toBe('Inasistencia'); expect(registrarInasistencia).toHaveBeenCalledTimes(1);
});
it.each(['CITA_INASISTENCIA_ANTICIPADA', 'CITA_EDICION_OBSOLETA', 'CITA_CON_ATENCION_DOCUMENTADA', 'CITA_TRANSICION_NO_PERMITIDA'])('conflicto de ausencia %s obliga a nueva revisión', async code => {
  registrarInasistencia.mockRejectedValue({ asistenciaEnviada: true, response: { status: 409, data: { error: { code, message: 'PRIVADO' } } } });
  const { result } = mount(); await act(async () => { await result.current.registrarInasistencia({ original: ausenciaElegible(), declaracion: true }); });
  expect(result.current.status).toBe('error'); expect(result.current.failure.message).not.toContain('PRIVADO'); expect(consultarAsistenciaCita).not.toHaveBeenCalled();
});
it('422 de ausencia solo publica sus campos permitidos, no declaraciones de otra operación', async () => {
  registrarInasistencia.mockRejectedValue({ asistenciaEnviada: true, response: { status: 422, data: { error: { details: {
    ausencia_verificada: ['Comprueba ausencia'], presencia_verificada: ['NO-SALIR'], diagnostico: ['NO-SALIR'] } } } } });
  const { result } = mount(); await act(async () => { await result.current.registrarInasistencia({ original: ausenciaElegible(), declaracion: true }); });
  expect(result.current.failure.fields).toEqual({ ausencia_verificada: 'Comprueba ausencia' });
});
it('pagehide conserva operación incierta de ausencia y descarta respuesta tardía', async () => {
  let complete; registrarInasistencia.mockImplementation(() => new Promise(resolve => { complete = resolve; })); const { result } = mount();
  let pendiente; act(() => { pendiente = result.current.registrarInasistencia({ original: ausenciaElegible(), declaracion: true }); });
  const signal = registrarInasistencia.mock.calls[0][1]; act(() => window.dispatchEvent(new Event('pagehide')));
  expect(signal.aborted).toBe(true); expect(result.current.status).toBe('incierto'); expect(result.current.operacion).toBe('INASISTENCIA');
  await act(async () => { complete(inasistencia()); await pendiente; }); expect(result.current.status).toBe('incierto');
});
