import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../auth/context/AuthContext';
import { consultarSesion } from '../../auth/services/auth.service';
import { crearRutas } from '../../../app/router';
import { api } from '../../../api/api';
import { destinoSeguro } from '../../../constants/roles';

vi.mock('../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
const version = '2026-10-21T12:00:00.123456Z';
const next = '2026-10-21T12:00:00.123457Z';
const root = `/administracion/medicos/${id}`;
const specialties = [
  { id_especialidad: id, nombre: 'Geriatría ficticia', activa: false, habilitada: true },
  { id_especialidad: '2', nombre: 'Pediatría ficticia', activa: true, habilitada: true },
  { id_especialidad: '3', nombre: 'Cardiología ficticia', activa: false, habilitada: false },
];
let current, calls, write;
const error = (config, status, code, details) => new AxiosError('SQL secreto', undefined, config, null, status ? { status, headers: { 'retry-after': '2' }, data: { error: { code, message: 'SQL secreto', details } } } : undefined);
function mount(path = root) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
const writes = () => calls.filter(c => c.method !== 'get');
beforeEach(() => {
  vi.resetAllMocks();
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración Ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  current = { id_medico: id, codigo_medico: 'MED-ficticio', registro_profesional: 'REG-ficticio', nombres: 'Profesional', apellidos: 'Ficticia', telefono: '70000000', correo_contacto: 'contacto@example.test', activo: true, cuenta_vinculada: true, actualizado_en: version, especialidades: specialties };
  calls = []; write = null;
  api.defaults.adapter = async config => {
    calls.push(config);
    if (config.url.includes('csrf-cookie')) return { config, status: 204, headers: {}, data: '' };
    if (config.url === '/especialidades') return { config, status: 200, headers: {}, data: { data: specialties, meta: { total: 3, current_page: 1, last_page: 1 } } };
    if (config.method !== 'get') {
      if (write) return write(config);
      const data = JSON.parse(config.data);
      if (config.url.endsWith('/estado')) current = { ...current, activo: data.activo, actualizado_en: next };
      else current = { ...current, especialidades: current.especialidades.map(item => ({ ...item, habilitada: data.especialidades.includes(item.id_especialidad) })), actualizado_en: next };
    }
    return { config, status: 200, headers: {}, data: { data: current } };
  };
});
afterEach(() => { api.defaults.adapter = original; vi.useRealTimers(); });
async function alter(user) {
  await user.click(await screen.findByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' }));
  await user.click(screen.getByRole('checkbox', { name: 'Cardiología ficticia (catálogo inactivo)' }));
}
async function reviewAndConfirm(user) {
  await user.click(screen.getByRole('button', { name: 'Revisar cambios', exact: true }));
  await user.click(screen.getByRole('button', { name: 'Confirmar habilitaciones' }));
}

describe('actividad e historial de habilitaciones CU-10 fase 4', () => {
  it.each(['Recepcionista', 'Médico', 'Paciente'])('ruta de habilitaciones denegada a %s antes de consultar', async rol => {
    consultarSesion.mockResolvedValue({ id_usuario: '2', nombre_visible: 'Persona Ficticia', correo_acceso: 'persona@example.test', rol }); mount(`${root}/especialidades`);
    await screen.findByRole('heading', { name: 'Acceso no permitido' }); expect(calls).toHaveLength(0);
  });
  it('espera sesión, conserva destino seguro y no consulta mientras restaura', async () => {
    let reject; consultarSesion.mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
    const router = mount(`${root}/especialidades`); expect(calls).toHaveLength(0);
    await act(async () => reject({ response: { status: 401 } })); await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(router.state.location.state.from).toBe(`${root}/especialidades`); expect(destinoSeguro(`${root}/especialidades`)).toBe(`${root}/especialidades`); expect(calls).toHaveLength(0);
  });
  it.each([true, false])('actividad desde %s exige confirmar y reconsulta sin tocar cuenta ni asociaciones', async active => {
    current.activo = active; const user = userEvent.setup(); mount();
    await user.click(await screen.findByRole('button', { name: active ? 'Inactivar profesional' : 'Activar profesional' })); expect(writes()).toHaveLength(0);
    await user.click(screen.getByRole('button', { name: 'Volver a revisar' })); expect(writes()).toHaveLength(0);
    await user.click(screen.getByRole('button', { name: active ? 'Inactivar profesional' : 'Activar profesional' }));
    await user.click(screen.getByRole('button', { name: 'Confirmar cambio de actividad' }));
    await screen.findByRole('button', { name: active ? 'Activar profesional' : 'Inactivar profesional' });
    expect(JSON.parse(writes()[0].data)).toEqual({ activo: !active, actualizado_en_original: version });
    expect(writes()[0].url).toBe(`/medicos/${id}/estado`); expect(current.cuenta_vinculada).toBe(true); expect(current.especialidades).toEqual(specialties);
    expect(calls.filter(c => c.url === `/medicos/${id}` && c.method === 'get')).toHaveLength(2);
  });
  it('vínculo incompatible no afirma activación ni cambia cuenta, ofrece gestión y consulta actual', async () => {
    current.activo = false; write = config => { throw error(config, 409, 'MEDICO_VINCULO_INCOMPATIBLE'); };
    const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('button', { name: 'Activar profesional' })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de actividad' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('rol y vínculo compatibles'); expect(screen.getByRole('link', { name: 'Consultar cuentas de usuario' })).toHaveAttribute('href', '/administracion/cuentas');
    expect(screen.getByRole('button', { name: 'Activar profesional' })).toBeDisabled(); expect(current.activo).toBe(false); expect(writes()).toHaveLength(1);
    await user.click(await screen.findByRole('button', { name: 'Usar estado actual y reiniciar confirmación' })); expect(screen.queryByRole('button', { name: 'Confirmar cambio de actividad' })).not.toBeInTheDocument();
  });
  it('actividad obsoleta conserva intención, adopta versión y exige otra confirmación', async () => {
    const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('button', { name: 'Inactivar profesional' }));
    write = config => { current = { ...current, actualizado_en: next }; throw error(config, 409, 'MEDICO_EDICION_OBSOLETA'); };
    await user.click(screen.getByRole('button', { name: 'Confirmar cambio de actividad' }));
    await user.click(await screen.findByRole('button', { name: 'Usar estado actual y reiniciar confirmación' })); expect(writes()).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Confirmar cambio de actividad' })).not.toBeInTheDocument();
    write = null; await user.click(screen.getByRole('button', { name: 'Inactivar profesional' })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de actividad' }));
    await screen.findByRole('button', { name: 'Activar profesional' }); expect(JSON.parse(writes()[1].data).actualizado_en_original).toBe(next);
  });
  it('detalle → gestión: conserva conjunto habilitado, retira y rehabilita con confirmación y PUT completo', async () => {
    const user = userEvent.setup(); mount(); await user.click(await screen.findByRole('link', { name: 'Gestionar habilitaciones' }));
    await screen.findByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' });
    expect(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' })).toBeChecked(); expect(screen.getByRole('checkbox', { name: 'Pediatría ficticia' })).toBeChecked(); expect(screen.getByRole('checkbox', { name: 'Cardiología ficticia (catálogo inactivo)' })).not.toBeChecked();
    await alter(user); await user.click(screen.getByRole('button', { name: 'Revisar cambios', exact: true }));
    const confirmation = screen.getByRole('heading', { name: 'Revisar cambios de habilitaciones' }).closest('section');
    expect(within(confirmation).getByText('Cardiología ficticia (catálogo inactivo)')).toBeInTheDocument(); expect(within(confirmation).getByText('Geriatría ficticia')).toBeInTheDocument(); expect(writes()).toHaveLength(0);
    await user.click(screen.getByRole('button', { name: 'Volver a la selección' })); expect(writes()).toHaveLength(0);
    await reviewAndConfirm(user); await screen.findByRole('heading', { name: 'Detalle de médico' }); await screen.findByRole('link', { name: 'Gestionar habilitaciones' });
    expect(writes()[0].method).toBe('put'); expect(JSON.parse(writes()[0].data)).toEqual({ especialidades: ['2', '3'], actualizado_en_original: version });
    expect(current.especialidades).toHaveLength(3); expect(current.especialidades[0].habilitada).toBe(false); expect(current.especialidades[2].habilitada).toBe(true); expect(current.especialidades[2].activa).toBe(false);
    expect(current.activo).toBe(true); expect(current.cuenta_vinculada).toBe(true);
  });
  it.each([true, false])('no-op y mínimo una habilitada incluso con actividad %s', async active => {
    current.activo = active; const user = userEvent.setup(); mount(`${root}/especialidades`); await screen.findByRole('button', { name: 'Revisar cambios', exact: true });
    await user.click(screen.getByRole('button', { name: 'Revisar cambios', exact: true })); await screen.findByText('No hay cambios de habilitaciones para guardar.'); expect(writes()).toHaveLength(0);
    await user.click(await screen.findByRole('button', { name: 'Quitar Geriatría ficticia' })); await user.click(screen.getByRole('button', { name: 'Quitar Pediatría ficticia' })); await user.click(screen.getByRole('button', { name: 'Revisar cambios', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('al menos una'); expect(screen.queryByRole('button', { name: 'Confirmar habilitaciones' })).not.toBeInTheDocument(); expect(writes()).toHaveLength(0);
  });
  it('409 citas futuras conserva borrador y todas las relaciones; permite corregir sin cancelar citas', async () => {
    write = config => { throw error(config, 409, 'MEDICO_ESPECIALIDAD_CON_CITAS_FUTURAS'); };
    const user = userEvent.setup(); mount(`${root}/especialidades`); await alter(user); await reviewAndConfirm(user);
    expect(await screen.findByRole('alert')).toHaveTextContent('No se guardó ningún cambio');
    expect(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' })).not.toBeChecked(); expect(screen.getByRole('checkbox', { name: 'Cardiología ficticia (catálogo inactivo)' })).toBeChecked();
    expect(current.especialidades).toEqual(specialties); expect(writes()).toHaveLength(1); expect(screen.queryByRole('button', { name: /cancelar cita|reprogramar/i })).not.toBeInTheDocument();
    // Se mantiene el par con obligaciones y se agrega otro; no se reproduce disponibilidad en React.
    await user.click(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' })); write = null;
    await reviewAndConfirm(user); await screen.findByRole('heading', { name: 'Detalle de médico' }); expect(JSON.parse(writes()[1].data).especialidades).toEqual(['2', '3', id]);
  });
  it('habilitaciones obsoletas preservan selección hasta adopción explícita y usan versión nueva', async () => {
    const user = userEvent.setup(); mount(`${root}/especialidades`); await alter(user);
    write = config => { current = { ...current, actualizado_en: next }; throw error(config, 409, 'MEDICO_EDICION_OBSOLETA'); };
    await reviewAndConfirm(user); await screen.findByRole('heading', { name: 'Versión actual del profesional' });
    expect(screen.getByRole('checkbox', { name: 'Cardiología ficticia (catálogo inactivo)' })).toBeChecked(); expect(screen.getByRole('checkbox', { name: 'Pediatría ficticia' })).toBeDisabled(); expect(writes()).toHaveLength(1);
    await user.click(await screen.findByRole('button', { name: 'Cargar datos actuales y descartar mi borrador' }));
    expect(screen.getByRole('checkbox', { name: 'Geriatría ficticia (catálogo inactivo)' })).toBeChecked(); expect(screen.getByRole('checkbox', { name: 'Cardiología ficticia (catálogo inactivo)' })).not.toBeChecked();
    write = null; await alter(user); await reviewAndConfirm(user); await screen.findByRole('heading', { name: 'Detalle de médico' }); expect(JSON.parse(writes()[1].data).actualizado_en_original).toBe(next);
  });
  it.each([419, 422, 403, 409])('habilitaciones error %s conserva conjunto y no reenvía automáticamente', async status => {
    write = config => { throw error(config, status, status === 409 ? 'MEDICO_SIN_ESPECIALIDADES_HABILITADAS' : undefined, { especialidades: ['Revisa el conjunto.'], password: ['SECRETO'] }); };
    const user = userEvent.setup(); mount(`${root}/especialidades`); await alter(user); await reviewAndConfirm(user); await screen.findByRole('alert');
    expect(screen.getByRole('checkbox', { name: 'Cardiología ficticia (catálogo inactivo)' })).toBeChecked(); expect(writes()).toHaveLength(1);
    if (status === 422) expect(screen.getByText('Revisa el conjunto.')).toBeInTheDocument();
    expect(screen.queryByText(/SQL secreto|SECRETO/)).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Confirmar habilitaciones' })).not.toBeInTheDocument();
  });
  it.each(['actividad', 'habilitaciones'])('red incierta de %s exige consulta actual y bloquea reenvío', async type => {
    write = config => { throw error(config); }; const user = userEvent.setup(); mount(type === 'actividad' ? root : `${root}/especialidades`);
    if (type === 'actividad') { await user.click(await screen.findByRole('button', { name: 'Inactivar profesional' })); await user.click(screen.getByRole('button', { name: 'Confirmar cambio de actividad' })); }
    else { await alter(user); await reviewAndConfirm(user); }
    await screen.findByRole('heading', { name: 'Versión actual del profesional' }); expect(writes()).toHaveLength(1); expect(screen.getByRole('button', { name: type === 'actividad' ? 'Inactivar profesional' : 'Revisar cambios', exact: true })).toBeDisabled();
  });
  it('doble confirmación no duplica PUT', async () => {
    let finish; write = config => new Promise(resolve => { finish = () => resolve({ config, status: 200, headers: {}, data: { data: current } }); });
    const user = userEvent.setup(); mount(`${root}/especialidades`); await alter(user); await user.click(screen.getByRole('button', { name: 'Revisar cambios', exact: true }));
    const form = screen.getByRole('form', { name: 'Editar habilitaciones' }); fireEvent.submit(form); fireEvent.submit(form); await waitFor(() => expect(writes()).toHaveLength(1));
    await act(async () => finish()); await screen.findByRole('heading', { name: 'Detalle de médico' });
  });
  it('429 requiere espera y otra confirmación manual, sin repetir PUT', async () => {
    const user = userEvent.setup(); mount(`${root}/especialidades`); await alter(user); await user.click(screen.getByRole('button', { name: 'Revisar cambios', exact: true }));
    vi.useFakeTimers({ shouldAdvanceTime: true }); write = config => { throw error(config, 429); };
    fireEvent.submit(screen.getByRole('form', { name: 'Editar habilitaciones' })); await screen.findByRole('alert'); expect(screen.getByRole('button', { name: 'Revisar cambios', exact: true })).toBeDisabled();
    await act(async () => vi.advanceTimersByTime(2500)); expect(screen.getByRole('button', { name: 'Revisar cambios', exact: true })).toBeEnabled(); expect(writes()).toHaveLength(1);
  });
  it('401 en PUT retira selección privada y dirige a login', async () => {
    write = config => { throw error(config, 401); }; const user = userEvent.setup(); const router = mount(`${root}/especialidades`); await alter(user); await reviewAndConfirm(user);
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' }); expect(router.state.location.pathname).toBe('/login'); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });
  it('parámetro inválido no consulta y 404 no expone respuesta técnica', async () => {
    const router = mount('/administracion/medicos/0/especialidades'); await screen.findByRole('heading', { name: 'Médico no disponible' }); expect(calls).toHaveLength(0);
    api.defaults.adapter = async config => { throw error(config, 404); }; await act(() => router.navigate(`${root}/especialidades`)); expect(await screen.findByRole('alert')).toHaveTextContent('No se encontró el médico'); expect(screen.queryByText('SQL secreto')).not.toBeInTheDocument();
  });
});
