import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { AuthProvider } from '../../../auth/context/AuthContext';
import { consultarSesion } from '../../../auth/services/auth.service';
import { crearRutas } from '../../../../app/router';
import { api } from '../../../../api/api';

vi.mock('../../../auth/services/auth.service', () => ({ consultarSesion: vi.fn(), iniciarSesion: vi.fn(), cerrarSesion: vi.fn() }));
const original = api.defaults.adapter;
const id = '9223372036854775807';
const evento = { id_evento: id, tipo_evento: 'CUENTA_DESACTIVADA', tipo_actor: 'USUARIO',
  id_usuario_responsable: '9007199254740993', rol_responsable: 'Administrador', id_usuario_objetivo: '7', rol_objetivo: 'Médico',
  modulo: 'USUARIOS', operacion: 'DESACTIVAR', resultado: 'EXITO', ocurrido_en: '2026-10-22T02:30:01.123456Z' };
let calls, respond;
const payload = (data = [evento], meta = {}) => ({ data, meta: { total: 41, current_page: 1, last_page: 3, per_page: 20, ...meta },
  links: { next: 'https://ajeno.example/secretos' } });
const response = (config, data = payload()) => ({ config, status: 200, headers: {}, data });
const fallo = (config, status, details = {}) => new AxiosError('SQL secreto del proxy', undefined, config, null,
  status ? { status, data: { error: { code: 'PRUEBA', message: 'SQL secreto del proxy', details } }, headers: { 'retry-after': '1' } } : undefined);
function mount() {
  const router = createMemoryRouter(crearRutas(), { initialEntries: ['/administracion/bitacora'] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = [];
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Administración Ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  respond = config => response(config, payload([evento], { current_page: Number(config.params.page) }));
  api.defaults.adapter = async config => { calls.push(config); return config.url === '/bitacora' ? respond(config)
    : response(config, { data: { ...evento, version_formato: 1, referencias: {}, cambios_seguros: {}, metadatos_seguros: {} } }); };
});
afterEach(() => { api.defaults.adapter = original; });
const apply = () => screen.getByRole('button', { name: 'Aplicar filtros' });
async function advanced(user) { await user.click(screen.getByText('Filtros avanzados', { selector: 'summary' })); }

describe('listado, filtros y paginación CU-30 fase 2', () => {
  it('tabla adaptable conserva las seis columnas y el acceso de solo lectura', async () => {
    mount(); const table = await screen.findByRole('table'); expect(table.parentElement).toHaveClass('query-table');
    expect(within(table).getAllByRole('columnheader')).toHaveLength(6);
    expect(table).toHaveTextContent('CUENTA_DESACTIVADA'); expect(table).toHaveTextContent('9007199254740993'); expect(table).toHaveTextContent('Cuenta #7');
    expect(within(table).getByRole('link', { name: `Ver evento ${id}` })).toHaveAttribute('href', `${'/administracion/bitacora'}/${id}`);
    expect(within(table).queryByRole('button')).not.toBeInTheDocument(); expect(calls.every(call => call.method === 'get')).toBe(true);
  });
  it('presenta cabecera segura, fecha de sede y roles históricos distintos, sin escrituras', async () => {
    const router = mount(); const user = userEvent.setup();
    const table = await screen.findByRole('table');
    expect(within(table).getByText('21/10/2026, 22:30:01')).toHaveAttribute('datetime', evento.ocurrido_en);
    expect(within(table).getByText('Usuario #9007199254740993')).toBeInTheDocument();
    expect(within(table).getByText('Cuenta #7')).toBeInTheDocument();
    expect(within(table).getByText('Rol histórico: Administrador')).toBeInTheDocument();
    expect(within(table).getByText('Rol histórico: Médico')).toBeInTheDocument();
    expect(within(table).getByText('Éxito')).toBeInTheDocument();
    expect(calls).toHaveLength(1); expect(calls[0].method).toBe('get');
    await user.click(screen.getByRole('link', { name: `Ver evento ${id}` }));
    await screen.findByRole('heading', { name: 'Detalle de evento' });
    expect(router.state.location.pathname).toBe(`/administracion/bitacora/${id}`);
    expect(calls).toHaveLength(2); expect(calls[1].url).toBe(`/bitacora/${id}`);
  });
  it('combina todos los filtros solo al aplicar y conserva texto exacto y BIGINT', async () => {
    mount(); const user = userEvent.setup(); await screen.findByRole('table');
    await user.type(screen.getByLabelText('Módulo'), ' Instalacion ');
    await user.selectOptions(screen.getByLabelText('Resultado'), 'DENEGADO');
    fireEvent.change(screen.getByLabelText('Fecha desde'), { target: { value: '2026-10-20' } });
    fireEvent.change(screen.getByLabelText('Fecha hasta'), { target: { value: '2026-10-21' } });
    await advanced(user);
    await user.type(screen.getByLabelText('ID de usuario responsable'), id);
    await user.type(screen.getByLabelText('ID de cuenta objetivo'), '9007199254740993');
    await user.selectOptions(screen.getByLabelText('Origen del evento'), 'ANONIMO');
    await user.type(screen.getByLabelText('Operación'), ' VERIFICAR ');
    await user.type(screen.getByLabelText('Tipo de evento'), ' CODIGO_INVALIDO ');
    await user.type(screen.getByLabelText('Dirección IP'), '2001:db8::1');
    await user.selectOptions(screen.getByLabelText('Tipo de recurso'), 'CuentaPrueba');
    await user.type(screen.getByLabelText('ID del recurso'), 'Ficticio_01');
    await user.selectOptions(screen.getByLabelText('Eventos por página'), '100');
    expect(calls).toHaveLength(1);
    await user.click(apply()); await screen.findByRole('table');
    expect(calls).toHaveLength(2);
    expect(calls[1].params).toEqual({ fecha_desde: '2026-10-20', fecha_hasta: '2026-10-21', id_usuario_responsable: id,
      id_usuario_objetivo: '9007199254740993', tipo_actor: 'ANONIMO', modulo: 'Instalacion', operacion: 'VERIFICAR',
      tipo_evento: 'CODIGO_INVALIDO', resultado: 'DENEGADO', direccion_ip: '2001:db8::1', entidad_referida: 'CuentaPrueba',
      registro_referido: 'Ficticio_01', page: 1, per_page: '100' });
  });
  it('pagina con meta sin seguir enlaces externos, conserva filtros aplicados y reinicia al buscar o limpiar', async () => {
    mount(); const user = userEvent.setup(); await screen.findByRole('table');
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeDisabled();
    await user.type(screen.getByLabelText('Módulo'), 'MEDICOS'); await user.click(apply()); await screen.findByRole('table');
    await user.type(screen.getByLabelText('Módulo'), '_borrador');
    await user.click(screen.getByRole('button', { name: 'Siguiente' })); await screen.findByText('41 eventos · página 2 de 3');
    expect(calls.at(-1).params).toMatchObject({ modulo: 'MEDICOS', page: 2 });
    expect(screen.getByLabelText('Módulo')).toHaveValue('MEDICOS_borrador');
    await user.click(screen.getByRole('button', { name: 'Siguiente' })); await screen.findByText('41 eventos · página 3 de 3');
    expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
    await user.click(apply()); await screen.findByText('41 eventos · página 1 de 3');
    expect(calls.at(-1).params).toMatchObject({ modulo: 'MEDICOS_borrador', page: 1 });
    await user.click(screen.getByRole('button', { name: 'Limpiar filtros' })); await screen.findByRole('table');
    expect(calls.at(-1).params).toEqual({ page: 1, per_page: '20' });
    expect(screen.getByLabelText('Módulo')).toHaveValue('');
    expect(calls.every(c => c.url === '/bitacora' && c.method === 'get')).toBe(true);
  });
  it('muestra origen sistema/anónimo y respeta el orden del servidor sin inventar usuarios', async () => {
    respond = config => response(config, payload([
      { ...evento, id_evento: '2', tipo_actor: 'SISTEMA', id_usuario_responsable: null, rol_responsable: null, id_usuario_objetivo: null, rol_objetivo: null, resultado: 'FALLO' },
      { ...evento, id_evento: '3', tipo_actor: 'ANONIMO', id_usuario_responsable: null, rol_responsable: null, id_usuario_objetivo: null, rol_objetivo: null, resultado: 'DENEGADO' },
    ]));
    mount(); const table = await screen.findByRole('table');
    expect(within(table).getByText('Sistema')).toBeInTheDocument(); expect(within(table).getByText('Anónimo')).toBeInTheDocument();
    expect(within(table).getAllByText('Sin cuenta objetivo')).toHaveLength(2);
    expect(within(table).queryByText(/Usuario #/)).not.toBeInTheDocument();
    expect(within(table).getAllByRole('link').map(link => link.getAttribute('href'))).toEqual(['/administracion/bitacora/2', '/administracion/bitacora/3']);
  });
  it('presenta vacío y totales del servidor sin simular eventos', async () => {
    respond = config => response(config, payload([], { total: 0, current_page: 1, last_page: 1 })); mount();
    await screen.findByRole('heading', { name: 'No hay eventos para estos filtros' });
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.getByText('0 eventos · página 1 de 1')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeDisabled(); expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
  });
  it('página vacía posterior a la última mantiene total/página y permite volver', async () => {
    respond = config => response(config, payload([], { total: 1, current_page: 3, last_page: 1 })); mount(); const user = userEvent.setup();
    await screen.findByText('1 eventos · página 3 de 1');
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeEnabled();
    await user.click(screen.getByRole('button', { name: 'Anterior' }));
    await waitFor(() => expect(calls).toHaveLength(2)); expect(calls[1].params.page).toBe(2);
  });
  it('422 abre filtros avanzados, asocia y enfoca errores conservando el borrador para corregir', async () => {
    mount(); const user = userEvent.setup(); await screen.findByRole('table');
    await advanced(user); await user.type(screen.getByLabelText('ID del recurso'), '7');
    await advanced(user);
    respond = config => { throw fallo(config, 422, { registro_referido: ['Selecciona la entidad del recurso.'], password: ['dato privado descartado'] }); };
    await user.click(apply()); await screen.findByRole('alert');
    expect(screen.getByText('Filtros avanzados', { selector: 'summary' }).parentElement).toHaveAttribute('open');
    expect(screen.getByLabelText('ID del recurso')).toHaveValue('7');
    expect(screen.getByLabelText('ID del recurso')).toHaveAttribute('aria-invalid', 'true');
    await waitFor(() => expect(screen.getByLabelText('ID del recurso')).toHaveFocus());
    expect(screen.getByText('Selecciona la entidad del recurso.')).toBeInTheDocument();
    expect(screen.queryByText('dato privado descartado')).not.toBeInTheDocument();
    respond = config => response(config);
    await user.selectOptions(screen.getByLabelText('Tipo de recurso'), 'Paciente'); await user.click(apply());
    await screen.findByRole('table'); expect(calls.at(-1).params).toMatchObject({ entidad_referida: 'Paciente', registro_referido: '7' });
  });
  it.each([403, 419, 500, undefined])('error %s retira resultados, conserva filtros y solo reconsulta manualmente', async status => {
    mount(); const user = userEvent.setup(); await screen.findByRole('table');
    await user.type(screen.getByLabelText('Módulo'), 'MEDICOS');
    respond = config => { throw fallo(config, status); }; await user.click(apply());
    await screen.findByRole('alert'); expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.queryByText(/SQL secreto/)).not.toBeInTheDocument(); expect(screen.getByLabelText('Módulo')).toHaveValue('MEDICOS');
    expect(calls).toHaveLength(2);
    if (status === 403) expect(screen.getByRole('alert')).toHaveTextContent('No tienes permiso');
    if (status === 419) expect(screen.getByRole('alert')).toHaveTextContent('Comprueba tu sesión');
    if (!status) expect(screen.getByRole('alert')).toHaveTextContent('Comprueba tu conexión');
    respond = config => response(config); await user.click(screen.getByRole('button', { name: 'Volver a consultar' }));
    await screen.findByRole('table'); expect(calls).toHaveLength(3); expect(calls[2].params.modulo).toBe('MEDICOS');
  });
  it('401 retira eventos y dirige al login mediante invalidación central de sesión', async () => {
    mount(); const user = userEvent.setup(); await screen.findByRole('table');
    respond = config => { throw fallo(config, 401); }; await user.click(apply());
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.queryByText(evento.tipo_evento)).not.toBeInTheDocument(); expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });
  it('429 respeta Retry-After y no reintenta automáticamente al terminar la espera', async () => {
    respond = config => { throw fallo(config, 429); }; mount(); const user = userEvent.setup();
    await screen.findByRole('alert'); expect(apply()).toBeDisabled(); expect(screen.getByRole('button', { name: 'Limpiar filtros' })).toBeDisabled();
    const retry = screen.getByRole('button', { name: 'Volver a consultar' }); expect(retry).toBeDisabled();
    await waitFor(() => expect(retry).toBeEnabled(), { timeout: 2500 }); expect(calls).toHaveLength(1);
    respond = config => response(config); await user.click(retry); await screen.findByRole('table'); expect(calls).toHaveLength(2);
  });
  it('muestra carga y cancela/descarta la respuesta anterior al aplicar otra búsqueda', async () => {
    let completar, oldSignal;
    respond = config => new Promise(resolve => { oldSignal = config.signal; completar = () => resolve(response(config, payload([{ ...evento, tipo_evento: 'EVENTO_OBSOLETO' }]))); });
    mount(); const user = userEvent.setup(); await screen.findByText('Consultando eventos…');
    await user.type(screen.getByLabelText('Módulo'), 'MEDICOS');
    respond = config => response(config); await user.click(apply()); await screen.findByRole('table');
    expect(oldSignal.aborted).toBe(true);
    await act(async () => completar());
    expect(screen.queryByText('EVENTO_OBSOLETO')).not.toBeInTheDocument(); expect(screen.getByText(evento.tipo_evento)).toBeInTheDocument();
  });
});
