import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion, cerrarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';
import { propuesta, cita, seleccion } from '../utils/reservaFixtures';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
let calls, role, confirmTask, resultTask, proposalTask, csrfTask;
const ok = (config, data, status = 200) => ({ config, status, headers: {}, data });
const fail = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: {}, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
const posts = path => calls.filter(c => c.method === 'post' && c.url === path);
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [role === 'Paciente' ? '/reservar-cita' : '/recepcion/citas/nueva'] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = []; role = 'Paciente'; confirmTask = null; resultTask = null; proposalTask = null; csrfTask = null;
  consultarSesion.mockImplementation(async () => ({ id_usuario: '7', nombre_visible: 'Persona ficticia', correo_acceso: 'p@example.test', rol: role }));
  cerrarSesion.mockResolvedValue();
  api.defaults.adapter = async config => {
    calls.push(config);
    const meta = { total: 1, current_page: 1, last_page: 1 };
    if (config.url.includes('csrf-cookie')) return csrfTask ? csrfTask(config) : ok(config, '');
    if (config.url === '/reservas/propuesta') return proposalTask ? proposalTask(config) : ok(config, { data: { ...propuesta, motivo_breve: JSON.parse(config.data).motivo_breve } });
    if (config.url === '/citas') return confirmTask ? confirmTask(config) : ok(config, { data: cita, meta: { repetida: false } }, 201);
    if (config.url === '/reservas/resultado') return resultTask ? resultTask(config) : ok(config, { data: { registrada: true, cita } });
    if (config.url === '/pacientes') return ok(config, { data: [{ ...propuesta.paciente, documento_identidad: null, activo: true }], meta });
    if (config.url === '/reservas/especialidades') return ok(config, { data: [propuesta.especialidad], meta });
    if (config.url === '/reservas/medicos') return ok(config, { data: [{ ...propuesta.medico, consultorio: propuesta.consultorio }], meta });
    if (config.url === '/reservas/disponibilidad') return ok(config, { data: { ...propuesta, fecha: seleccion.fecha, paciente_reservable: true,
      turnos: [{ hora_inicio: seleccion.hora_inicio, revision_turno: seleccion.revision_turno, inicio_at: propuesta.inicio_at, fin_at: propuesta.fin_at }] }, meta });
    throw new Error('Petición inesperada');
  };
});
afterEach(() => { api.defaults.adapter = original; });
async function elegir(user, motivo = '0') {
  if (role === 'Recepcionista') await user.click(await screen.findByRole('button', { name: /Paciente Ficticio · PAC-FICTICIO/ }));
  await user.click(await screen.findByRole('button', { name: 'General ficticia' }));
  await user.click(await screen.findByRole('button', { name: /Médico Ficticio · MED-FICTICIO/ }));
  fireEvent.change(screen.getByLabelText('Fecha de atención'), { target: { value: seleccion.fecha } });
  await user.click(screen.getByRole('button', { name: 'Consultar turnos' }));
  await user.click(await screen.findByRole('radio', { name: '08:00:01' }));
  if (motivo) await user.type(screen.getByLabelText('Motivo breve (opcional)'), motivo);
}
async function revisar(user) { await user.click(screen.getByRole('button', { name: 'Revisar cita' })); await screen.findByRole('heading', { name: 'Revisar cita' }); }
async function confirmar(user) { await user.click(screen.getByRole('button', { name: role === 'Paciente' ? 'Reservar cita' : 'Programar cita' })); }
it.each(['Paciente', 'Recepcionista'])('guía de %s conserva revisión, resultado y payload sin navegación ficticia', async rol => {
  role = rol; const user = userEvent.setup(); mount(); await elegir(user);
  const progress = screen.getByRole('list', { name: 'Etapas de la reserva' });
  expect(progress.querySelector('[aria-current="step"]')).toHaveTextContent('Buscar turno'); expect(within(progress).queryByRole('button')).not.toBeInTheDocument();
  await revisar(user); expect(progress.querySelector('[aria-current="step"]')).toHaveTextContent('Revisar y registrar'); expect(posts('/citas')).toHaveLength(0);
  await confirmar(user); await screen.findByRole('heading', { name: 'Cita registrada' });
  expect(progress.querySelector('[aria-current="step"]')).toHaveTextContent('Resultado de la reserva'); expect(posts('/citas')).toHaveLength(1);
  expect(JSON.parse(posts('/citas')[0].data)).toEqual({ propuesta: propuesta.propuesta, motivo_breve: '0' });
});
it('la etapa de resultado incierto no declara éxito ni permite otra creación', async () => {
  confirmTask = config => { throw fail(config, 500); }; const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user);
  await screen.findByRole('button', { name: 'Consultar resultado' });
  expect(screen.getByRole('list', { name: 'Etapas de la reserva' }).querySelector('[aria-current="step"]')).toHaveTextContent('Resultado de la reserva');
  expect(screen.queryByRole('heading', { name: 'Cita registrada' })).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
  expect(screen.getByText(/Este resumen es una propuesta; no acredita/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument();
});
it.each(['Paciente', 'Recepcionista'])('navegación modular conserva selección y revisión de %s sin nuevas peticiones', async rol => {
  role = rol; const user = userEvent.setup(); const router = mount(); await elegir(user, 'Motivo ficticio');
  const navigation = within(screen.getByRole('navigation', { name: 'Navegación principal' }));
  const count = calls.length;
  await user.click(navigation.getByRole('button', { name: 'Búsqueda y reserva de citas' }));
  await user.click(navigation.getByRole('button', { name: 'Usuarios y autenticación' }));
  expect(screen.getByRole('radio', { name: '08:00:01' })).toBeChecked();
  expect(screen.getByLabelText('Fecha de atención')).toHaveValue(seleccion.fecha);
  expect(screen.getByLabelText('Motivo breve (opcional)')).toHaveValue('Motivo ficticio'); expect(calls).toHaveLength(count);
  await revisar(user); const reviewCount = calls.length;
  await user.click(navigation.getByRole('button', { name: 'Búsqueda y reserva de citas' }));
  expect(screen.getByRole('heading', { name: 'Revisar cita' })).toBeInTheDocument();
  expect(calls).toHaveLength(reviewCount); expect(posts('/reservas/propuesta')).toHaveLength(1); expect(posts('/citas')).toHaveLength(0);
  expect([...router.state.blockers.values()].every(blocker => blocker.state === 'unblocked')).toBe(true);
});
it('desplegar durante resultado incierto no activa salida ni reenvía la reserva', async () => {
  confirmTask = config => { throw fail(config, 500); }; const user = userEvent.setup(); const router = mount();
  await elegir(user); await revisar(user); await confirmar(user); await screen.findByRole('button', { name: 'Consultar resultado' });
  const count = calls.length;
  await user.click(within(screen.getByRole('navigation', { name: 'Navegación principal' })).getByRole('button', { name: 'Usuarios y autenticación' }));
  expect(screen.getByRole('heading', { name: 'Consultar resultado de la reserva' })).toBeInTheDocument();
  expect(calls).toHaveLength(count); expect(posts('/citas')).toHaveLength(1);
  expect([...router.state.blockers.values()].every(blocker => blocker.state === 'unblocked')).toBe(true);
});
it.each(['Paciente', 'Recepcionista'])('revisión explícita y registro correcto para %s con resumen real', async rol => {
  role = rol; const user = userEvent.setup(); mount(); await elegir(user); await revisar(user);
  expect(posts('/citas')).toHaveLength(0); expect(screen.getByText('Dirección ficticia')).toBeInTheDocument();
  expect(screen.getByText('08:00:01', { exact: false })).toBeInTheDocument(); expect(screen.getByText('0', { selector: 'dd' })).toBeInTheDocument();
  expect(screen.queryByText(propuesta.propuesta)).not.toBeInTheDocument(); expect(screen.queryByText(propuesta.id_propuesta)).not.toBeInTheDocument();
  const body = JSON.parse(posts('/reservas/propuesta')[0].data);
  expect(body).toEqual({ ...seleccion, ...(rol === 'Recepcionista' ? { id_paciente: '3' } : {}) });
  await confirmar(user); await screen.findByRole('heading', { name: 'Cita registrada' });
  expect(screen.getByText('Programada')).toBeInTheDocument(); expect(screen.getByText('CIT-FICTICIO')).toBeInTheDocument();
  expect(screen.queryByText('Dirección ficticia')).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
  expect(JSON.parse(posts('/citas')[0].data)).toEqual({ propuesta: propuesta.propuesta, motivo_breve: '0' });
});
it('volver desde revisión retira propuesta y exige nueva selección explícita', async () => {
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user);
  await user.click(screen.getByRole('button', { name: 'Volver a buscar y revisar' }));
  expect(screen.queryByRole('heading', { name: 'Revisar cita' })).not.toBeInTheDocument(); expect(screen.queryByRole('radio')).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(0);
});
it('motivo demasiado largo se valida antes de solicitar propuesta', async () => {
  const user = userEvent.setup(); mount(); await elegir(user, ''); fireEvent.change(screen.getByLabelText('Motivo breve (opcional)'), { target: { value: 'a'.repeat(501) } });
  await user.click(screen.getByRole('button', { name: 'Revisar cita' })); expect(screen.getByLabelText('Motivo breve (opcional)')).toHaveAttribute('aria-invalid', 'true'); expect(posts('/reservas/propuesta')).toHaveLength(0);
});
it.each([undefined, 500])('resultado incierto %s solo consulta y recupera la misma cita', async status => {
  confirmTask = c => { throw fail(c, status); }; const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user);
  await screen.findByRole('heading', { name: 'Consultar resultado de la reserva' }); expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument();
  expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
  await user.click(screen.getByRole('button', { name: 'Consultar resultado' })); await screen.findByRole('heading', { name: 'Cita registrada' });
  expect(screen.getByText('CIT-FICTICIO')).toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1); expect(JSON.parse(posts('/reservas/resultado')[0].data)).toEqual({ propuesta: propuesta.propuesta });
});
it('resultado ausente no reenvía y solo una confirmación explícita usa el mismo sobre', async () => {
  confirmTask = c => { throw fail(c, 500); }; resultTask = c => ok(c, { data: { registrada: false } });
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await screen.findByRole('button', { name: 'Consultar resultado' });
  await user.click(screen.getByRole('button', { name: 'Consultar resultado' })); await screen.findByText(/No se encontró una cita registrada/);
  expect(posts('/citas')).toHaveLength(1); expect(posts('/reservas/propuesta')).toHaveLength(1);
  confirmTask = null; await user.click(screen.getByRole('button', { name: 'Confirmar nuevamente esta propuesta' })); await screen.findByRole('heading', { name: 'Cita registrada' });
  expect(posts('/citas')).toHaveLength(2); expect(posts('/citas')[0].data).toBe(posts('/citas')[1].data);
});
it('consulta fallida mantiene incertidumbre sin volver a habilitar creación', async () => {
  confirmTask = c => { throw fail(c, 500); }; resultTask = c => { throw fail(c, 500); };
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await user.click(await screen.findByRole('button', { name: 'Consultar resultado' }));
  await screen.findByRole('alert'); expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
});
it('200 de repetición y consulta muestran estado vigente sin fingir Programada', async () => {
  confirmTask = c => ok(c, { data: { ...cita, estado: 'Cancelada', duracion_minutos: 20, consultorio_atencion: 'Sala vigente distinta' }, meta: { repetida: true } });
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await screen.findByRole('heading', { name: 'Cita registrada' });
  expect(screen.getByText('Cancelada')).toBeInTheDocument(); expect(screen.getByText('20 minutos')).toBeInTheDocument(); expect(screen.getByText('Sala vigente distinta')).toBeInTheDocument(); expect(screen.queryByText('Programada')).not.toBeInTheDocument();
});
it('respuesta 201 inválida queda incierta y no declara éxito', async () => {
  confirmTask = c => ok(c, { data: {} }, 201); const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user);
  await screen.findByRole('button', { name: 'Consultar resultado' }); expect(screen.queryByRole('heading', { name: 'Cita registrada' })).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
});
it.each(['CITA_PROPUESTA_OBSOLETA', 'CITA_TURNO_NO_DISPONIBLE', 'CITA_PACIENTE_SOLAPADO', 'CITA_PACIENTE_NO_DISPONIBLE'])('409 %s exige volver a buscar, no sustituye propuesta', async code => {
  confirmTask = c => { throw fail(c, 409, code); }; const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user);
  await screen.findByRole('heading', { name: 'Revisa la selección' }); expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1); expect(posts('/reservas/propuesta')).toHaveLength(1);
});
it('fallo previo de CSRF no envía reserva ni fuerza consulta de resultado', async () => {
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); csrfTask = c => { throw fail(c); }; await confirmar(user);
  await screen.findByText(/No se envió la reserva/); expect(posts('/citas')).toHaveLength(0); expect(screen.queryByRole('button', { name: 'Consultar resultado' })).not.toBeInTheDocument();
});
it('419 no reenvía automáticamente y conserva revisión para otra acción explícita', async () => {
  confirmTask = c => { throw fail(c, 419); }; const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await screen.findByRole('alert');
  expect(posts('/citas')).toHaveLength(1); expect(screen.getByRole('button', { name: 'Reservar cita' })).toBeEnabled(); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
});
it('422 solo muestra campos seguros y obliga a revisar otra vez', async () => {
  confirmTask = c => { throw fail(c, 422, 'VALIDACION', { motivo_breve: ['Revisa el motivo.'], password: ['NO-MOSTRAR'] }); };
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await screen.findByText('Motivo: Revisa el motivo.');
  expect(screen.queryByText('NO-MOSTRAR')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument();
});
it('doble submit en revisión envía una sola confirmación', async () => {
  let resolve; confirmTask = c => new Promise(done => { resolve = () => done(ok(c, { data: cita, meta: { repetida: false } }, 201)); });
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); const button = screen.getByRole('button', { name: 'Reservar cita' });
  fireEvent.click(button); fireEvent.click(button); await waitFor(() => expect(resolve).toBeTypeOf('function')); expect(posts('/citas')).toHaveLength(1);
  await act(async () => resolve()); await screen.findByRole('heading', { name: 'Cita registrada' });
});
it('fallo al preparar propuesta no registra nada ni permite confirmar', async () => {
  proposalTask = c => { throw fail(c, 409, 'CITA_PROPUESTA_OBSOLETA'); }; const user = userEvent.setup(); mount(); await elegir(user); await user.click(screen.getByRole('button', { name: 'Revisar cita' }));
  await screen.findByRole('heading', { name: 'Revisa la selección' }); expect(posts('/citas')).toHaveLength(0); expect(posts('/reservas/propuesta')).toHaveLength(1);
});
it('salir con resultado incierto exige advertencia y no persiste el comprobante', async () => {
  const storage = vi.spyOn(Storage.prototype, 'setItem'); confirmTask = c => { throw fail(c, 500); };
  const user = userEvent.setup(); const router = mount(); await elegir(user); await revisar(user); await confirmar(user); await screen.findByRole('button', { name: 'Consultar resultado' });
  await user.click(screen.getByRole('link', { name: 'Inicio' })); await screen.findByText(/Si sales perderás esta comprobación/);
  await user.click(screen.getByRole('button', { name: 'Permanecer y consultar' })); expect(router.state.location.pathname).toBe('/reservar-cita');
  expect(router.state.location.state).toBeNull(); expect(storage).not.toHaveBeenCalled(); storage.mockRestore();
});
it('401 en confirmación limpia propuesta y permite login sin conservarla', async () => {
  confirmTask = c => { throw fail(c, 401); }; const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText('PAC-FICTICIO')).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
});
it('429 mantiene revisión pero bloquea confirmación hasta Retry-After', async () => {
  confirmTask = c => { const e = fail(c, 429); e.response.headers['retry-after'] = '60'; throw e; };
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await screen.findByText(/Espera 60 segundos/);
  expect(screen.getByRole('button', { name: 'Reservar cita' })).toBeDisabled(); expect(posts('/citas')).toHaveLength(1);
});
it('logout durante guardado descarta la respuesta tardía sin mostrar recibo', async () => {
  let resolve; confirmTask = c => new Promise(done => { resolve = () => done(ok(c, { data: cita, meta: { repetida: false } }, 201)); });
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await waitFor(() => expect(resolve).toBeTypeOf('function'));
  await user.click(screen.getByRole('button', { name: 'Cerrar sesión' })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
  await act(async () => resolve()); expect(screen.queryByText('CIT-FICTICIO')).not.toBeInTheDocument(); expect(screen.queryByText(propuesta.propuesta)).not.toBeInTheDocument();
});
it('abandono explícito descarta token y no recupera revisión al volver', async () => {
  confirmTask = c => { throw fail(c, 500); }; const user = userEvent.setup(); const router = mount(); await elegir(user); await revisar(user); await confirmar(user);
  await screen.findByRole('button', { name: 'Consultar resultado' }); await user.click(screen.getByRole('link', { name: 'Inicio' }));
  await user.click(await screen.findByRole('button', { name: 'Salir sin conservar la comprobación' })); await waitFor(() => expect(router.state.location.pathname).toBe('/inicio'));
  await act(() => router.navigate('/reservar-cita')); await screen.findByLabelText('Nombre de especialidad');
  expect(screen.queryByRole('button', { name: 'Consultar resultado' })).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
});
it('logout fallido durante POST no deja Registrando bloqueado al descartar una época anterior', async () => {
  let resolve; confirmTask = c => new Promise(done => { resolve = () => done(ok(c, { data: cita, meta: { repetida: false } }, 201)); });
  cerrarSesion.mockRejectedValue(new Error('Sin red'));
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await waitFor(() => expect(resolve).toBeTypeOf('function'));
  await user.click(screen.getByRole('button', { name: 'Cerrar sesión' })); await screen.findByText(/No pudimos confirmar el cierre/);
  await act(async () => resolve());
  await screen.findByRole('button', { name: 'Consultar resultado' }); expect(screen.queryByText('CIT-FICTICIO')).not.toBeInTheDocument();
  expect(posts('/citas')).toHaveLength(1);
});
it('403 al preparar CSRF no deja confirmar una revisión ya no autorizada', async () => {
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user);
  csrfTask = c => { throw fail(c, 403); }; await confirmar(user); await screen.findByRole('alert');
  expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument(); expect(screen.queryByText('Dirección ficticia')).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(0);
});
it.each([403, 404, 419, 422, 429, 500, undefined])('propuesta HTTP %s no habilita guardado ni consulta resultado de una reserva inexistente', async status => {
  proposalTask = c => { throw fail(c, status, 'VALIDACION', { propuesta: ['NO-RENDERIZAR-SECRETO'] }); };
  const user = userEvent.setup(); mount(); await elegir(user); await user.click(screen.getByRole('button', { name: 'Revisar cita' }));
  await screen.findByRole('heading', { name: 'Revisa la selección' });
  expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Consultar resultado' })).not.toBeInTheDocument();
  expect(screen.queryByText('NO-RENDERIZAR-SECRETO')).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(0);
});
it.each([403, 404, 419, 422, 429, 500, undefined])('consulta de resultado HTTP %s mantiene incertidumbre sin recrear reserva', async status => {
  confirmTask = c => { throw fail(c, 500); }; resultTask = c => { throw fail(c, status); };
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user);
  await user.click(await screen.findByRole('button', { name: 'Consultar resultado' })); await screen.findByRole('alert');
  expect(screen.queryByRole('button', { name: 'Reservar cita' })).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(1);
  expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
  if (status === 403) { expect(screen.queryByText('Dirección ficticia')).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Consultar resultado' })).toBeDisabled(); }
});
it('401 consultando resultado limpia comprobante sin que el blocker impida login', async () => {
  confirmTask = c => { throw fail(c, 500); }; resultTask = c => { throw fail(c, 401); };
  const user = userEvent.setup(); mount(); await elegir(user); await revisar(user); await confirmar(user); await user.click(await screen.findByRole('button', { name: 'Consultar resultado' }));
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText('Dirección ficticia')).not.toBeInTheDocument();
});
it.each(['propuesta', 'resultado'])('cambio de rol descarta respuesta tardía de %s', async etapa => {
  let resolve;
  const pending = c => new Promise(done => { resolve = () => done(ok(c, { data: etapa === 'propuesta' ? propuesta : { registrada: true, cita } })); });
  const user = userEvent.setup(); mount(); await elegir(user);
  if (etapa === 'propuesta') { proposalTask = pending; await user.click(screen.getByRole('button', { name: 'Revisar cita' })); }
  else { await revisar(user); confirmTask = c => { throw fail(c, 500); }; await confirmar(user); resultTask = pending; await user.click(await screen.findByRole('button', { name: 'Consultar resultado' })); }
  await waitFor(() => expect(resolve).toBeTypeOf('function'));
  role = 'Administrador'; act(() => window.dispatchEvent(new Event('focus')));
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); await act(async () => resolve());
  expect(screen.queryByText('CIT-FICTICIO')).not.toBeInTheDocument(); expect(screen.queryByText('Dirección ficticia')).not.toBeInTheDocument();
});
it('salir durante propuesta descarta la respuesta sin bloquear como si hubiera guardado', async () => {
  let resolve; proposalTask = c => new Promise(done => { resolve = () => done(ok(c, { data: propuesta })); });
  const user = userEvent.setup(); const router = mount(); await elegir(user); await user.click(screen.getByRole('button', { name: 'Revisar cita' }));
  await waitFor(() => expect(resolve).toBeTypeOf('function')); await user.click(screen.getByRole('link', { name: 'Inicio' }));
  await waitFor(() => expect(router.state.location.pathname).toBe('/inicio')); await act(async () => resolve());
  expect(screen.queryByText('Dirección ficticia')).not.toBeInTheDocument(); expect(posts('/citas')).toHaveLength(0);
});
it('advertencia de salida desaparece al completarse guardado y no arrastra navegación vieja', async () => {
  let resolve; confirmTask = c => new Promise(done => { resolve = () => done(ok(c, { data: cita, meta: { repetida: false } }, 201)); });
  const user = userEvent.setup(); const router = mount(); await elegir(user); await revisar(user); await confirmar(user);
  await waitFor(() => expect(resolve).toBeTypeOf('function')); await user.click(screen.getByRole('link', { name: 'Inicio' }));
  await screen.findByText(/Si sales perderás esta comprobación/); await act(async () => resolve());
  await screen.findByRole('heading', { name: 'Cita registrada' });
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Permanecer y consultar' })).not.toBeInTheDocument());
  expect(router.state.location.pathname).toBe('/reservar-cita');
});
