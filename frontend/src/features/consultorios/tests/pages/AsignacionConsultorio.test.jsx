import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
const version = '2026-10-22T12:00:00.123456Z', next = '2026-10-22T12:00:00.123457Z';
const root = `/administracion/medicos/${id}/consultorio`;
const destination = { id_consultorio: '9007199254740993', codigo_consultorio: 'DESTINO', nombre: 'Sala destino ficticia', activo: true };
const origin = { id_consultorio: '2', codigo_consultorio: 'ORIGEN', nombre: 'Sala origen ficticia', activo: true };
let calls, current, impacto, write, readImpact, roomPages, roomRead;
const writes = () => calls.filter(c => c.method === 'put');
const previews = () => calls.filter(c => c.url.endsWith('/impacto'));
const error = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: {}, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
function mount(path = root) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = []; write = null; readImpact = null; roomRead = null; roomPages = 1;
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  current = { id_medico: id, actualizado_en: version, consultorio: origin };
  impacto = { puede_confirmar: true, huella_impacto: 'a'.repeat(64), totales: { conflictos: 0, citas_trasladadas: 2 }, elementos: [
    { codigo: 'CAMBIO_UBICACION', id_cita: '9007199254740994', id_medico: id, id_consultorio: '2', estado: 'Confirmada', inicio_at: '2026-11-01T12:00:00.123456Z', fin_at: '2026-11-01T12:15:00.123456Z' },
  ] };
  api.defaults.adapter = async config => {
    calls.push(config); const ok = data => ({ config, status: 200, headers: {}, data });
    if (config.url.includes('csrf-cookie')) return ok('');
    if (config.url === `/medicos/${id}`) return ok({ data: { id_medico: id, codigo_medico: 'MED-FICTICIO', nombres: 'Médico', apellidos: 'Ficticio', activo: false } });
    if (config.url === '/consultorios') return roomRead ? roomRead(config) : ok({ data: [destination, origin], meta: { current_page: config.params.page, last_page: roomPages, total: roomPages * 2 } });
    if (config.url.endsWith('/impacto')) return readImpact ? readImpact(config) : ok({ data: impacto, links: { next: 'https://ajeno.invalid/no-usar' }, meta: { current_page: JSON.parse(config.data).page, last_page: 3, total: 42 } });
    if (config.method === 'put') {
      if (write) return write(config);
      current = { ...current, actualizado_en: next, consultorio: destination, citas_trasladadas: impacto.totales.citas_trasladadas };
    }
    return ok({ data: current });
  };
});
afterEach(() => { api.defaults.adapter = original; });
async function select(user) {
  await screen.findByRole('option', { name: 'DESTINO — Sala destino ficticia' });
  await user.selectOptions(screen.getByLabelText('Seleccionar consultorio de destino'), destination.id_consultorio);
}
async function preview(user) {
  await select(user); await user.click(screen.getByRole('button', { name: 'Consultar impacto', exact: true })); await screen.findByRole('heading', { name: 'Revisa el impacto completo' });
}
async function confirm(user) {
  if (impacto.totales.citas_trasladadas) await user.click(screen.getByRole('checkbox'));
  await user.click(screen.getByRole('button', { name: 'Revisar confirmación' })); await user.click(screen.getByRole('button', { name: 'Guardar asignación' }));
}

it.each(['Recepcionista', 'Médico', 'Paciente'])('rechaza ruta directa de %s sin peticiones de planificación', async rol => {
  consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona', correo_acceso: 'persona@example.test', rol }); mount();
  await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
});
it.each(['0', '9223372036854775808', 'invalido'])('ID Médico %s inválido no consulta', async value => {
  mount(`/administracion/medicos/${value}/consultorio`); await screen.findByRole('heading', { name: 'Médico no disponible' }); expect(calls).toHaveLength(0);
});
it('muestra Médico inactivo y asignación sin habilitarlo ni modificar su cuenta', async () => {
  mount(); await screen.findByText('Médico Ficticio'); expect(screen.getByText(/Médico inactivo/)).toBeInTheDocument();
  expect(screen.getByText('ORIGEN — Sala origen ficticia', { selector: 'dd' })).toBeInTheDocument(); expect(writes()).toHaveLength(0); expect(previews()).toHaveLength(0);
});
it('requiere destino antes de consultar impacto', async () => {
  const user = userEvent.setup(); mount(); await screen.findByText('Médico Ficticio'); await user.click(screen.getByRole('button', { name: 'Consultar impacto', exact: true }));
  expect(screen.getByLabelText('Seleccionar consultorio de destino')).toHaveAttribute('aria-invalid', 'true'); expect(previews()).toHaveLength(0);
});
it('traslado exige consentimiento de todas las citas y confirmación antes de PUT', async () => {
  const user = userEvent.setup(); mount(); await preview(user);
  expect(screen.getByRole('button', { name: 'Revisar confirmación' })).toBeDisabled();
  expect(screen.getByText('Confirmada')).toBeInTheDocument(); expect(screen.getByText(/01\/11\/2026,? 08:00:00/)).toBeInTheDocument();
  await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar confirmación' }));
  expect(writes()).toHaveLength(0); await user.click(screen.getByRole('button', { name: 'Volver a revisar' })); expect(writes()).toHaveLength(0);
  await user.click(screen.getByRole('button', { name: 'Revisar confirmación' })); await user.click(screen.getByRole('button', { name: 'Guardar asignación' }));
  await screen.findByText(/Asignación guardada. Citas con cambio de ubicación: 2/);
  expect(JSON.parse(writes()[0].data)).toEqual({ id_consultorio: destination.id_consultorio, actualizado_en_original: version, huella_impacto: 'a'.repeat(64), confirmar_traslados: true });
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Consultar impacto', exact: true })).toBeDisabled();
  await user.click(screen.getByRole('button', { name: 'Consultar asignación actual' })); await screen.findByText(/Asignación actual consultada/);
  expect(screen.getByRole('button', { name: 'Consultar impacto', exact: true })).toBeEnabled();
});
it.each([null, destination])('primera asignación/no-op sin traslados permite confirmar sin checkbox con origen %s', async sala => {
  current.consultorio = sala; impacto.totales.citas_trasladadas = 0; impacto.elementos = [];
  const user = userEvent.setup(); mount(); await preview(user); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); await confirm(user);
  await screen.findByText(/Asignación guardada/); expect(JSON.parse(writes()[0].data).confirmar_traslados).toBe(false);
});
it('pagina impacto con POST de la misma propuesta sin seguir enlaces y retira consentimiento', async () => {
  const user = userEvent.setup(); mount(); await preview(user); await user.click(screen.getByRole('checkbox'));
  await user.click(screen.getByRole('button', { name: 'Impacto siguiente' })); await screen.findByText(/página 2 de 3/);
  expect(JSON.parse(previews()[1].data)).toEqual({ id_consultorio: destination.id_consultorio, actualizado_en_original: version, page: 2, per_page: 20 });
  expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(calls.some(c => c.url.includes('ajeno.invalid'))).toBe(false);
});
it('huella distinta entre páginas descarta impacto y obliga a consultar primera página', async () => {
  const user = userEvent.setup(); mount(); await preview(user); await user.click(screen.getByRole('checkbox'));
  impacto = { ...impacto, huella_impacto: 'b'.repeat(64) }; await user.click(screen.getByRole('button', { name: 'Impacto siguiente' }));
  await screen.findByText(/impacto cambió entre páginas/); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
  await user.click(screen.getByRole('button', { name: 'Consultar impacto', exact: true })); await screen.findByText(/página 1 de 3/);
  expect(JSON.parse(previews().at(-1).data).page).toBe(1);
});
it('cambio de destino borra impacto y consentimiento sin permitir PUT anterior', async () => {
  const user = userEvent.setup(); mount(); await preview(user); await user.click(screen.getByRole('checkbox'));
  await user.selectOptions(screen.getByLabelText('Seleccionar consultorio de destino'), origin.id_consultorio);
  expect(screen.queryByRole('heading', { name: 'Revisa el impacto completo' })).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
});
it('selección busca en servidor y conserva destino aunque no esté en otra página', async () => {
  roomPages = 3; const user = userEvent.setup(); mount(); await select(user);
  roomRead = config => ({ config, status: 200, headers: {}, data: { data: [origin], meta: { current_page: config.params.page, last_page: 3, total: 41 } } });
  await user.type(screen.getByLabelText('Buscar código o nombre de consultorio'), 'otro'); await user.click(screen.getByRole('button', { name: 'Buscar consultorio' }));
  await waitFor(() => expect(calls.filter(c => c.url === '/consultorios').at(-1).params.q).toBe('otro'));
  expect(screen.getByLabelText('Seleccionar consultorio de destino')).toHaveValue(destination.id_consultorio);
  await user.click(screen.getByRole('button', { name: 'Siguiente', exact: true }));
  await waitFor(() => expect(calls.filter(c => c.url === '/consultorios').at(-1).params.page).toBe(2));
  expect(calls.filter(c => c.url === '/consultorios').at(-1).params.activo).toBe('1');
});
it('conflictos se muestran sin exponer campos ajenos ni opciones de traslado parcial', async () => {
  impacto.puede_confirmar = false; impacto.totales.conflictos = 1;
  impacto.elementos = [{ codigo: 'ATENCION_NO_TRASLADABLE', id_cita: '123', motivo: 'Diagnóstico secreto', paciente: 'Persona secreta', estado: 'Programada' }];
  const user = userEvent.setup(); mount(); await preview(user); expect(screen.getByText(/Atención iniciada o documentada: no trasladable/)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Revisar confirmación' })).not.toBeInTheDocument(); expect(screen.queryByText('Diagnóstico secreto')).not.toBeInTheDocument();
  expect(screen.queryByText('Persona secreta')).not.toBeInTheDocument(); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
});
it.each(['ASIGNACION_IMPACTO_OBSOLETO', 'PLANIFICACION_CONFLICTO', 'ATENCION_NO_TRASLADABLE', 'ASIGNACION_CONFIRMACION_REQUERIDA', 'CONSULTORIO_NO_DISPONIBLE'])('409 %s invalida revisión y consentimiento sin reenvío', async code => {
  write = config => { throw error(config, 409, code); }; const user = userEvent.setup(); mount(); await preview(user); await confirm(user);
  await screen.findByRole('alert'); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(writes()).toHaveLength(1);
  expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(screen.getByLabelText('Seleccionar consultorio de destino')).toHaveValue(destination.id_consultorio);
});
it('versión Médico obsoleta requiere consultar base vigente antes de otra vista previa', async () => {
  write = config => { current = { ...current, actualizado_en: next }; throw error(config, 409, 'MEDICO_EDICION_OBSOLETA'); };
  const user = userEvent.setup(); mount(); await preview(user); await confirm(user); await screen.findByRole('alert');
  expect(screen.getByRole('button', { name: 'Consultar impacto', exact: true })).toBeDisabled();
  await user.click(screen.getByRole('button', { name: 'Consultar asignación actual' })); await screen.findByText(/Asignación actual consultada/);
  await user.click(screen.getByRole('button', { name: 'Consultar impacto', exact: true })); await screen.findByRole('checkbox');
  expect(JSON.parse(previews().at(-1).data).actualizado_en_original).toBe(next);
});
it.each([undefined, 500])('PUT incierto %s bloquea confirmación hasta reconsultar asignación', async status => {
  write = config => { throw error(config, status); }; const user = userEvent.setup(); mount(); await preview(user); await confirm(user);
  await screen.findByText(/Puede haberse guardado/); expect(screen.getByRole('button', { name: 'Consultar impacto', exact: true })).toBeDisabled(); expect(writes()).toHaveLength(1);
  await user.click(screen.getByRole('button', { name: 'Consultar asignación actual' })); await screen.findByText(/Asignación actual consultada/); expect(writes()).toHaveLength(1);
});
it('respuesta de impacto tardía se descarta al cambiar destino', async () => {
  let resolve; readImpact = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: impacto, meta: { current_page: 1, last_page: 1, total: 1 } } }); });
  const user = userEvent.setup(); mount(); await select(user); await user.click(screen.getByRole('button', { name: 'Consultar impacto', exact: true }));
  await waitFor(() => expect(resolve).toBeTypeOf('function')); await user.selectOptions(screen.getByLabelText('Seleccionar consultorio de destino'), origin.id_consultorio);
  await act(async () => resolve()); expect(screen.queryByRole('heading', { name: 'Revisa el impacto completo' })).not.toBeInTheDocument();
});
it('doble submit de confirmación produce un solo PUT', async () => {
  let resolve; write = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: { ...current, consultorio: destination, citas_trasladadas: 2 } } }); });
  const user = userEvent.setup(); mount(); await preview(user); await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: 'Revisar confirmación' }));
  const form = screen.getByRole('form', { name: 'Asignar consultorio fijo' }); fireEvent.submit(form); fireEvent.submit(form);
  await waitFor(() => expect(writes()).toHaveLength(1)); await act(async () => resolve()); await screen.findByText(/Asignación guardada/);
});
it.each([419, 422, 429])('impacto HTTP %s muestra error seguro y no habilita escritura', async status => {
  readImpact = config => { const e = error(config, status, 'VALIDACION', { id_consultorio: ['Revisa el consultorio.'], secreto: ['No mostrar'] }); if (status === 429) e.response.headers['retry-after'] = '60'; throw e; };
  const user = userEvent.setup(); mount(); await select(user); await user.click(screen.getByRole('button', { name: 'Consultar impacto', exact: true }));
  await screen.findByRole('alert'); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument(); expect(screen.queryByText('No mostrar')).not.toBeInTheDocument();
  expect(writes()).toHaveLength(0); if (status === 429) expect(screen.getByRole('button', { name: 'Consultar impacto', exact: true })).toBeDisabled();
});
it('401 en confirmación elimina información y conduce al login', async () => {
  write = config => { throw error(config, 401); }; const user = userEvent.setup(); mount(); await preview(user); await confirm(user);
  await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(screen.queryByText('Médico Ficticio')).not.toBeInTheDocument();
});

it.each([403, 404])('confirmación HTTP %s retira revisión y bloquea nuevos envíos hasta reconsultar', async status => {
  write = config => { throw error(config, status); }; const user = userEvent.setup(); mount(); await preview(user); await confirm(user);
  await screen.findByRole('alert'); expect(screen.getByRole('button', { name: 'Consultar impacto', exact: true })).toBeDisabled(); expect(writes()).toHaveLength(1);
  expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
});
it('cambiar a otro Médico desmonta datos e impacto pendientes', async () => {
  let resolve; readImpact = config => new Promise(done => { resolve = () => done({ config, status: 200, headers: {}, data: { data: impacto, meta: { current_page: 1, last_page: 1, total: 1 } } }); });
  const user = userEvent.setup(); const router = mount(); await select(user); await user.click(screen.getByRole('button', { name: 'Consultar impacto', exact: true }));
  await waitFor(() => expect(resolve).toBeTypeOf('function')); await act(() => router.navigate('/administracion/medicos/invalido/consultorio')); await act(async () => resolve());
  expect(screen.getByRole('heading', { name: 'Médico no disponible' })).toBeInTheDocument(); expect(screen.queryByText('Médico Ficticio')).not.toBeInTheDocument();
});
it('código de diagnóstico desconocido y campos extra no se interpretan ni se renderizan como HTML', async () => {
  impacto.puede_confirmar = false; impacto.elementos = [{ codigo: '__proto__', nombre: '<script>secreto</script>', id_cita: '123', inicio_at: '2026-02-30T12:00:00.000000Z' }];
  const user = userEvent.setup(); mount(); await preview(user); expect(screen.getByText('Conflicto de asignación')).toBeInTheDocument();
  expect(screen.getByText('No disponible')).toBeInTheDocument(); expect(screen.queryByText('<script>secreto</script>')).not.toBeInTheDocument();
});
