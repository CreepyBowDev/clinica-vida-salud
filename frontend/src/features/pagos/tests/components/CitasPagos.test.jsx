import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import DisponibilidadTurnos from '../../../citas/components/DisponibilidadTurnos';
import ReservaRegistro from '../../../citas/components/ReservaRegistro';
import AsignarMonto from '../../components/AsignarMonto';
import FinanzasCita from '../../components/FinanzasCita';
import { propuesta, seleccion } from '../../../citas/tests/utils/reservaFixtures';
import { obligacion, precio } from '../utils/pagoFixtures';

const original = api.defaults.adapter;
let calls, task;
const ok = (config, data, status = 200) => ({ config, data, status, headers: {} });
beforeEach(() => {
  calls = []; task = null;
  api.defaults.adapter = async c => { calls.push(c); if (c.url === csrfURL) return ok(c, ''); if (task) return task(c); throw new Error('Red no simulada bloqueada'); };
});
afterEach(() => { api.defaults.adapter = original; });
function disponibilidad(requiere, incorporada = true) {
  task = c => ok(c, { data: { ...propuesta, fecha: seleccion.fecha, paciente_reservable: true, requiere_monto_bob: requiere, incorporacion_financiera_habilitada: incorporada,
    turnos: [{ inicio_at: propuesta.inicio_at, fin_at: propuesta.fin_at, hora_inicio: seleccion.hora_inicio, revision_turno: seleccion.revision_turno }] }, meta: { total: 1, current_page: 1, last_page: 1 } });
}
it.each(['Administrador', 'Recepcionista'])('%s reserva incorporada exige monto antes de revisar sin cálculo USD local', async () => {
  disponibilidad(true); const onRevisar = vi.fn(); render(<DisponibilidadTurnos criterios={{ ...seleccion, id_paciente: '3' }} onRevisar={onRevisar} />);
  await userEvent.click(await screen.findByRole('radio', { name: seleccion.hora_inicio }));
   await userEvent.click(screen.getByRole('button', { name: 'Revisar cita' })); expect(onRevisar).not.toHaveBeenCalled();
   expect(screen.getByLabelText('Monto de la consulta (BOB)')).toHaveFocus();
   expect(screen.getByLabelText('Monto de la consulta (BOB)')).toHaveAttribute('aria-invalid', 'true');
  fireEvent.change(screen.getByLabelText('Monto de la consulta (BOB)'), { target: { value: '70,01' } });
  await userEvent.click(screen.getByRole('button', { name: 'Revisar cita' }));
  expect(onRevisar.mock.calls[0][0]).toMatchObject({ monto_bob: '70.01' }); expect(onRevisar.mock.calls[0][0]).not.toHaveProperty('monto_usd');
});
it.each([true, false])('reserva propia no envía monto ni fabrica deuda, incorporación %s', async incorporada => {
  disponibilidad(false, incorporada); const onRevisar = vi.fn(); render(<DisponibilidadTurnos criterios={seleccion} onRevisar={onRevisar} />);
  await userEvent.click(await screen.findByRole('radio', { name: seleccion.hora_inicio })); expect(screen.queryByLabelText('Monto de la consulta (BOB)')).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole('button', { name: 'Revisar cita' })); expect(onRevisar.mock.calls[0][0]).not.toHaveProperty('monto_bob');
});
it('revisión de reserva utiliza BOB/tasa/USD exactos del servidor', () => {
  render(<ReservaRegistro administrativa registro={{ status: 'revision', propuesta: { ...propuesta, precio }, confirmar: vi.fn(), remaining: 0 }} />);
  expect(screen.getByText('Bs 70,00')).toBeInTheDocument(); expect(screen.getByText('USD 10,00')).toBeInTheDocument(); expect(screen.getByText('7,00 BOB por USD')).toBeInTheDocument();
});
const cita = { id_cita: '9', codigo_cita: 'CITA-FICTICIA' };
it('asignación posterior revisa y confirma solo la propuesta, no edita el precio después', async () => {
  task = c => c.url.endsWith('/propuesta') ? ok(c, { data: { propuesta: 'token', precio, cita } }) : ok(c, { data: obligacion() }, 201);
  const update = vi.fn(); render(<AsignarMonto cita={cita} onActualizar={update} />);
  await userEvent.type(screen.getByLabelText('Monto de la consulta (BOB)'), '70,00'); await userEvent.click(screen.getByRole('button', { name: 'Revisar monto' }));
  await screen.findByText('USD 10,00'); expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
  expect(screen.getByText('Revisar monto de la cita').closest('.payment-assignment-review')).toHaveFocus();
  await userEvent.click(screen.getByRole('button', { name: 'Confirmar asignación de monto' })); await waitFor(() => expect(update).toHaveBeenCalledTimes(1));
  expect(JSON.parse(calls.filter(c => c.method === 'post')[1].data)).toEqual({ propuesta: 'token' });
});
it('asignación obsoleta exige consultar antes de volver a ofrecer otra revisión', async () => {
  task = c => { if (c.url.endsWith('/propuesta')) return ok(c, { data: { propuesta: 'token', precio, cita } }); throw new AxiosError('SQL', undefined, c, null, { status: 409, data: { error: { code: 'PAGO_REVISION_OBSOLETA' } }, headers: {} }); };
  render(<AsignarMonto cita={cita} onActualizar={vi.fn()} />); await userEvent.type(screen.getByLabelText('Monto de la consulta (BOB)'), '70');
  await userEvent.click(screen.getByRole('button', { name: 'Revisar monto' })); await screen.findByText('USD 10,00'); await userEvent.click(screen.getByRole('button', { name: 'Confirmar asignación de monto' }));
  await screen.findByRole('alert'); expect(screen.queryByRole('button', { name: 'Revisar monto' })).not.toBeInTheDocument(); expect(screen.queryByText('SQL')).not.toBeInTheDocument();
});
it('médico no recibe proyección ni controles financieros incluso ante extras inesperados', () => {
  render(<FinanzasCita rol="Médico" cita={{ ...cita, situacion_precio: 'PendienteAsignacion', puede_asignar_monto: true }} onActualizar={vi.fn()} />);
  expect(screen.queryByRole('heading', { name: 'Precio de la cita' })).not.toBeInTheDocument(); expect(calls).toHaveLength(0);
});
it('asignación valida el campo y enfoca antes de preparar una propuesta', async () => {
  render(<AsignarMonto cita={cita} onActualizar={vi.fn()} />);
  await userEvent.click(screen.getByRole('button', { name: 'Revisar monto' }));
  const input = screen.getByLabelText('Monto de la consulta (BOB)');
  expect(input).toHaveFocus(); expect(input).toHaveAttribute('aria-invalid', 'true'); expect(calls).toHaveLength(0);
  await userEvent.type(input, '7000,01'); await userEvent.click(screen.getByRole('button', { name: 'Revisar monto' }));
  expect(input).toHaveFocus(); expect(calls).toHaveLength(0);
});
it('asignación incierta recupera la misma propuesta sin preparar otra ni cambiar datos', async () => {
  let lost = true;
  task = c => {
    if (c.url.endsWith('/propuesta')) return ok(c, { data: { propuesta: 'misma-propuesta-con-clave', precio, cita } });
    if (lost) { lost = false; throw new AxiosError('Respuesta perdida', 'ERR_NETWORK', c); }
    return ok(c, { data: obligacion() });
  };
  const update = vi.fn(); render(<AsignarMonto cita={cita} onActualizar={update} />);
  await userEvent.type(screen.getByLabelText('Monto de la consulta (BOB)'), '70');
  await userEvent.click(screen.getByRole('button', { name: 'Revisar monto' }));
  await userEvent.click(await screen.findByRole('button', { name: 'Confirmar asignación de monto' }));
  const recover = await screen.findByRole('button', { name: 'Recuperar misma asignación' });
  expect(screen.queryByLabelText('Monto de la consulta (BOB)')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Volver a editar' })).not.toBeInTheDocument(); expect(update).not.toHaveBeenCalled();
  await userEvent.click(recover); await waitFor(() => expect(update).toHaveBeenCalledOnce());
  const posts = calls.filter(c => c.method === 'post');
  expect(posts).toHaveLength(3); expect(posts[1].url).toBe(posts[2].url); expect(posts[1].data).toBe(posts[2].data);
  expect(JSON.parse(posts[2].data)).toEqual({ propuesta: 'misma-propuesta-con-clave' });
});
it.each(['NoIncorporada', 'PendienteAsignacion', 'Asignado'])('representa %s sin fabricar importes ni controles', situacion_precio => {
  render(<MemoryRouter><FinanzasCita rol="Paciente" cita={{ ...cita, situacion_precio, precio: situacion_precio === 'Asignado' ? precio : null }} /></MemoryRouter>);
  expect(screen.queryByText('Bs 0,00')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Revisar monto' })).not.toBeInTheDocument();
  if (situacion_precio === 'Asignado') expect(screen.getByText('Bs 70,00')).toBeInTheDocument();
  else expect(screen.queryByLabelText('Importes históricos')).not.toBeInTheDocument();
  if (situacion_precio === 'NoIncorporada') expect(screen.getByText(/No se le atribuye una deuda/)).toBeInTheDocument();
  if (situacion_precio === 'PendienteAsignacion') expect(screen.getByText(/Todavía no hay un precio para pagar/)).toBeInTheDocument();
});
it.each(['Administrador', 'Recepcionista'])('%s necesita capacidad explícita para asignar', rol => {
  const data = { ...cita, situacion_precio: 'PendienteAsignacion', puede_asignar_monto: false };
  const view = render(<MemoryRouter><FinanzasCita rol={rol} cita={data} /></MemoryRouter>);
  expect(screen.queryByLabelText('Monto de la consulta (BOB)')).not.toBeInTheDocument();
  view.rerender(<MemoryRouter><FinanzasCita rol={rol} cita={{ ...data, puede_asignar_monto: true }} /></MemoryRouter>);
  expect(screen.getByLabelText('Monto de la consulta (BOB)')).toBeInTheDocument(); expect(calls).toHaveLength(0);
});
it('devolución pendiente permanece separada del recibo original y no afirma éxito', () => {
  render(<MemoryRouter><FinanzasCita rol="Paciente" cita={{ ...cita, situacion_precio: 'Asignado', precio,
    situacion_financiera: { exigible: false, liquidada: true, origen_liquidacion: 'Simulado', devolucion: 'Pendiente', recibo_academico: { numero: 'REC-ACA-FICTICIO' } } }} /></MemoryRouter>);
  const refund = screen.getByRole('region', { name: 'Devolución posterior' });
  expect(refund).toHaveTextContent('Aún no hay una devolución exitosa confirmada');
  expect(within(refund).queryByRole('button')).not.toBeInTheDocument();
  expect(within(screen.getByRole('region', { name: 'Recibo original' })).getByRole('button', { name: /Descargar recibo/ })).toBeInTheDocument();
  expect(calls).toHaveLength(0);
});
