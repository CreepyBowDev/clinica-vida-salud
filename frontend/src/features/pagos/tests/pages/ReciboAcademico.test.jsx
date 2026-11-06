import { Blob as NodeBlob } from 'node:buffer';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api, avanzarSesion } from '../../../../api/api';
import { crearRutas } from '../../../../app/router';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { detalle as detalleCita, sesion, cita } from '../../../agenda/tests/utils/agendaFixtures';
import { O, C, detalle, precio } from '../utils/pagoFixtures';
import { descargarRecibo } from '../../services/pago.service';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter, numero = `REC-ACA-${C}`, recibo = { numero };
let rol, calls, state, binary, click;
function mount(path = rol === 'Paciente' ? `/mis-citas/${cita.id_cita}` : `/citas/${cita.id_cita}`) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  return { router, ...render(<AuthProvider><RouterProvider router={router} /></AuthProvider>) };
}
beforeEach(() => {
  vi.resetAllMocks(); rol = 'Paciente'; calls = [];
  state = { exigible: true, liquidada: true, vencida: false, origen_liquidacion: 'Simulado', devolucion: null, recibo_academico: recibo };
  consultarSesion.mockImplementation(async () => sesion(rol));
  vi.stubGlobal('Blob', NodeBlob);
  vi.stubGlobal('fetch', vi.fn(() => { throw new Error('Red externa prohibida'); }));
  vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:recibo'); static revokeObjectURL = vi.fn(); });
  click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  binary = c => ({ config: c, status: 200, headers: {}, data: new NodeBlob(['%PDF-1.7\n', 'a'.repeat(200)], { type: 'application/pdf' }) });
  api.defaults.adapter = async c => {
    calls.push(c);
    if (c.url.endsWith('/recibo')) return binary(c);
    if (c.url === `/citas/${cita.id_cita}`) return { config: c, status: 200, headers: {}, data: { data: { ...detalleCita(rol),
      incorporada_finanzas: true, situacion_precio: 'Asignado', puede_asignar_monto: false, obligacion_id: O, precio,
      ...(rol === 'Recepcionista' ? {} : { situacion_financiera: state }) } } };
    if (c.url === `/pagos/obligaciones/${O}`) return { config: c, status: 200, headers: {},
      data: detalle({ liquidada: true, origen_liquidacion: 'Simulado', recibo_academico: recibo }) };
    throw new Error('Petición inesperada');
  };
});
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); vi.unstubAllGlobals(); });

it.each(['Paciente', 'Administrador'])('%s ve Pagada en detalle, recarga y descarga el recibo con el ámbito correcto', async role => {
  rol = role;
  const first = mount();
  await screen.findByText('Pagada — simulación académica');
  expect(screen.getByText(/Origen académico simulado/)).toBeInTheDocument();
  expect(calls.some(c => c.url.endsWith('/recibo'))).toBe(false);
  first.unmount(); mount();
  await screen.findByText('Pagada — simulación académica');
  await userEvent.click(screen.getByRole('button', { name: 'Descargar recibo académico en PDF' }));
  await waitFor(() => expect(click).toHaveBeenCalledTimes(1));
  const req = calls.find(c => c.url.endsWith('/recibo'));
  expect(req).toMatchObject({ method: 'get', responseType: 'blob', sessionBound: true,
    params: rol === 'Administrador' ? { id_paciente: cita.paciente.id_paciente } : {} });
  expect(click.mock.instances[0].download).toBe(`${numero}.pdf`);
  expect(calls.every(c => c.method === 'get')).toBe(true);
  expect(fetch).not.toHaveBeenCalled();
});
it.each(['Recepcionista', 'Médico'])('%s no tiene recibo ni situación financiera nueva', async role => {
  rol = role; mount(`/citas/${cita.id_cita}`);
  await screen.findByRole('region', { name: `Cita ${cita.codigo_cita}` });
  expect(screen.queryByRole('button', { name: /Descargar recibo/ })).not.toBeInTheDocument();
  expect(screen.queryByText('Pagada — simulación académica')).not.toBeInTheDocument();
  expect(calls).toHaveLength(1);
});
it('sin cobro aplicado muestra pendiente y no ofrece recibo; GET no declara éxito', async () => {
  state = { ...state, liquidada: false, origen_liquidacion: null, recibo_academico: null };
  mount(); await screen.findByText('Pendiente de pago');
  expect(screen.queryByRole('button', { name: /Descargar recibo/ })).not.toBeInTheDocument();
  expect(calls.every(c => c.method === 'get')).toBe(true);
});
it('cancelación muestra devolución separada y conserva acceso al recibo original', async () => {
  state = { ...state, exigible: false, devolucion: 'Exitosa' };
  mount(); await screen.findByText('No exigible');
  expect(screen.getByText(/Devolución simulada: Exitosa/)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /Descargar recibo/ })).toBeInTheDocument();
});
it.each([403, 404, 419, 500])('descarga rechazada %s no entrega bytes ni muestra errores técnicos', async status => {
  binary = c => { throw new AxiosError('SQL-PRIVADO', undefined, c, null, { status, headers: {},
    data: new NodeBlob([JSON.stringify({ error: { code: 'RECURSO_NO_ENCONTRADO', message: 'SQL-PRIVADO' } })], { type: 'application/json' }) }); };
  mount(); await userEvent.click(await screen.findByRole('button', { name: /Descargar recibo/ }));
  await screen.findByText(/No se pudo descargar el recibo académico/);
  expect(click).not.toHaveBeenCalled(); expect(URL.createObjectURL).not.toHaveBeenCalled();
  expect(screen.queryByText('SQL-PRIVADO')).not.toBeInTheDocument();
  expect(calls.filter(c => c.url.endsWith('/recibo'))).toHaveLength(1);
});
it('una respuesta HTML o un falso PDF no se descargan', async () => {
  for (const [type, text] of [['text/html', '<html>privado</html>'], ['application/pdf', 'NO ES PDF'.repeat(30)]]) {
    binary = c => ({ config: c, status: 200, headers: {}, data: new NodeBlob([text], { type }) });
    await expect(descargarRecibo({ id: O, rol: 'Paciente', numero })).rejects.toThrow();
  }
  expect(click).not.toHaveBeenCalled();
});
it('cambio de sesión durante la descarga descarta la respuesta', async () => {
  let finish;
  const valid = binary;
  binary = c => new Promise(resolve => { finish = () => resolve(valid(c)); });
  mount(); await userEvent.click(await screen.findByRole('button', { name: /Descargar recibo/ }));
  await waitFor(() => expect(finish).toBeTypeOf('function'));
  await act(async () => { avanzarSesion(); finish(); });
  expect(click).not.toHaveBeenCalled(); expect(URL.createObjectURL).not.toHaveBeenCalled();
});
it('la misma descarga está disponible desde obligación, no solamente desde agenda', async () => {
  mount(`/pagos/obligaciones/${O}`);
  await userEvent.click(await screen.findByRole('button', { name: /Descargar recibo/ }));
  await waitFor(() => expect(click).toHaveBeenCalledOnce());
});
it.each([{ rol: 'Recepcionista' }, { rol: 'Médico' }, { rol: 'Paciente', id_paciente: '9' }, { rol: 'Administrador' }])('el servicio rechaza ámbito incompatible %j antes de HTTP', async scope => {
  await expect(descargarRecibo({ id: O, numero, ...scope })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
