import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
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
