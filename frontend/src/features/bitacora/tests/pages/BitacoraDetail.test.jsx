import { act, render, screen, waitFor, within } from '@testing-library/react';
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
const root = '/administracion/bitacora';
const detail = `${root}/${id}`;
const base = { id_evento: id, tipo_evento: 'CUENTA_ROL_CAMBIADO', tipo_actor: 'USUARIO',
  id_usuario_responsable: '9007199254740993', rol_responsable: 'Administrador', id_usuario_objetivo: '9', rol_objetivo: 'Recepcionista',
  modulo: 'USUARIOS', operacion: 'CAMBIAR_ROL', resultado: 'EXITO', causa_codigo: null,
  ocurrido_en: '2026-10-22T02:30:01.123456Z', registrado_en: '2026-10-22T02:30:02.654321Z',
  direccion_ip: '2001:db8::1', procedencia_ip: 'PROXY_CONFIABLE', correlacion: 'f14429c8-8ef1-4a9c-b53b-d4915d82df27',
  entidad_referida: 'Usuario', registro_referido: '9', referencias: {}, id_evento_relacionado: '9007199254740995', version_formato: 1,
  cambios_seguros: { antes: { rol: 'Médico', estado_cuenta: 'Activa', id_medico: '9007199254740997' },
    despues: { rol: 'Recepcionista', estado_cuenta: 'Activa', id_medico: null } }, metadatos_seguros: {} };
let calls, current, respond;
const response = (config, data) => ({ config, status: 200, headers: {}, data });
const fallo = (config, status) => new AxiosError('SQL privado', undefined, config, null,
  status ? { status, headers: { 'retry-after': '1' }, data: { error: { code: 'PRUEBA', message: 'SQL privado' } } } : undefined);
function mount(path = detail) {
  const router = createMemoryRouter(crearRutas(), { initialEntries: [path] });
  render(<AuthProvider><RouterProvider router={router} /></AuthProvider>); return router;
}
beforeEach(() => {
  vi.resetAllMocks(); calls = []; current = structuredClone(base);
  consultarSesion.mockResolvedValue({ id_usuario: '1', nombre_visible: 'Persona Ficticia', correo_acceso: 'admin@example.test', rol: 'Administrador' });
  respond = config => response(config, { data: current });
  api.defaults.adapter = async config => {
    calls.push(config);
    return config.url === '/bitacora' ? response(config, { data: [base], meta: { total: 41, current_page: Number(config.params.page), last_page: 3, per_page: 20 } }) : respond(config);
  };
});
afterEach(() => { api.defaults.adapter = original; });
const dato = (region, name) => within(region).getByText(name, { selector: 'dt' }).parentElement;

describe('detalle seguro CU-30 fase 3', () => {
  it('consulta por BIGINT y diferencia fechas originales, responsables/objetivos y estados históricos', async () => {
    mount(); const cabecera = await screen.findByRole('region', { name: `Evento #${id}` });
    expect(dato(cabecera, 'Fecha del hecho')).toHaveTextContent('21/10/2026, 22:30:01');
    expect(dato(cabecera, 'Fecha de registro')).toHaveTextContent('21/10/2026, 22:30:02');
    expect(dato(cabecera, 'Fecha del hecho')).toHaveTextContent(base.ocurrido_en);
    expect(dato(cabecera, 'Fecha de registro')).toHaveTextContent(base.registrado_en);
    expect(dato(cabecera, 'ID de usuario responsable')).toHaveTextContent('9007199254740993');
    expect(dato(cabecera, 'ID de cuenta objetivo')).toHaveTextContent('9');
    expect(dato(cabecera, 'Rol histórico de cuenta objetivo')).toHaveTextContent('Recepcionista');
    expect(dato(cabecera, 'Procedencia de IP')).toHaveTextContent('Proxy confiable');
    expect(dato(cabecera, 'Correlación de la solicitud')).toHaveTextContent(base.correlacion);
    expect(dato(cabecera, 'ID de evento relacionado')).toHaveTextContent(base.id_evento_relacionado);
    const antes = screen.getByRole('heading', { name: 'Antes' }).parentElement;
    const despues = screen.getByRole('heading', { name: 'Después' }).parentElement;
    expect(dato(antes, 'Rol')).toHaveTextContent('Médico'); expect(dato(antes, 'ID de Médico vinculado')).toHaveTextContent('9007199254740997');
    expect(dato(despues, 'Rol')).toHaveTextContent('Recepcionista'); expect(dato(despues, 'ID de Médico vinculado')).toHaveTextContent('Sin vínculo');
    expect(calls).toHaveLength(1); expect(calls[0]).toMatchObject({ method: 'get', url: `/bitacora/${id}`, sessionBound: true });
    expect(screen.getAllByRole('link').every(link => !link.getAttribute('href').includes('9007199254740995'))).toBe(true);
    expect(within(screen.getByRole('main')).queryByRole('button')).not.toBeInTheDocument();
  });
  it.each([
    ['PACIENTE_CREADO', { sin_documento: true }, { id_paciente: '71' }, 'Registro administrativo sin documento: Sí.'],
    ['PACIENTE_ACTUALIZADO', { campos: ['telefono', 'recordatorios_habilitados'] }, { id_paciente: '71' }, 'Preferencia de recordatorios'],
    ['MEDICO_ESPECIALIDADES_CAMBIADAS', { campos: ['especialidades'], habilitaciones: { habilitadas: ['9007199254740993'], retiradas: [id] } }, { id_medico: '81' }, 'Especialidades habilitadas (IDs)'],
    ['ESPECIALIDAD_ACTUALIZADA', { campos: ['nombre', 'descripcion'] }, { id_especialidad: '91' }, 'Descripción'],
    ['PACIENTE_CUENTA_VINCULADA', { id_paciente: '71', id_codigo_activacion: id }, {}, 'ID del registro de activación (no es el código secreto)'],
  ])('%s presenta cambios y referencias conocidos sin volcar JSON', async (tipo, cambios, referencias, texto) => {
    current = { ...base, tipo_evento: tipo, cambios_seguros: cambios, referencias }; mount();
    const cambiosRegion = await screen.findByRole('region', { name: 'Cambios registrados' });
    expect(within(cambiosRegion).getByText(texto)).toBeInTheDocument();
    for (const [key, value] of Object.entries(referencias)) {
      expect(screen.getByRole('region', { name: 'Recursos referidos' })).toHaveTextContent(value);
    }
    expect(screen.queryByText(/"campos"/)).not.toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Recursos referidos' })).queryByRole('link')).not.toBeInTheDocument();
  });
  it.each([
    ['INSTALACION_ADMIN_INICIAL', 'AUTORIZACION_INSTALACION', 'Autorización de instalación; no demuestra control del buzón'],
    ['SEED_CUENTA_PRUEBA', 'SIMULADA_PRUEBAS', 'Verificación simulada para pruebas'],
  ])('%s explica metadatos de verificación sin afirmar control de correo real', async (tipo, verificacion, texto) => {
    current = { ...base, tipo_evento: tipo, cambios_seguros: {}, metadatos_seguros: { verificacion_correo: verificacion, metodo: 'SEEDER' } }; mount();
    const region = await screen.findByRole('region', { name: 'Metadatos del evento' });
    expect(region).toHaveTextContent(texto); expect(region).toHaveTextContent('Instalación mediante seeder');
  });
  it.each(['SISTEMA', 'ANONIMO'])('origen %s conserva ausencia de responsable, objetivo y datos opcionales', async origen => {
    current = { ...base, tipo_actor: origen, id_usuario_responsable: null, rol_responsable: null, id_usuario_objetivo: null, rol_objetivo: null,
      direccion_ip: null, procedencia_ip: null, correlacion: null, id_evento_relacionado: null,
      entidad_referida: null, registro_referido: null, referencias: {}, cambios_seguros: {}, metadatos_seguros: {}, causa_codigo: 'ENVIO_SIMULADO' }; mount();
    const region = await screen.findByRole('region', { name: `Evento #${id}` });
    expect(dato(region, 'Origen')).toHaveTextContent(origen === 'SISTEMA' ? 'Sistema' : 'Anónimo');
    expect(dato(region, 'ID de usuario responsable')).toHaveTextContent('Sin responsable autenticado');
    expect(dato(region, 'ID de cuenta objetivo')).toHaveTextContent('Sin cuenta objetivo');
    expect(dato(region, 'Causa general')).toHaveTextContent('Envío simulado (ENVIO_SIMULADO)');
    expect(screen.getByText('No hay cambios adicionales disponibles para este evento.')).toBeInTheDocument();
    expect(screen.getByText('No hay metadatos adicionales disponibles para este evento.')).toBeInTheDocument();
  });
  it('no presenta secretos, claves desconocidas, campos arbitrarios ni listas excesivas', async () => {
    current = { ...base, referencias: { id_medico: '7', secreto: 'ReferenciaPrivada' },
      cambios_seguros: { campos: ['telefono', 'password', 'diagnostico', 'CampoPrivado'], motivo_administrativo: 'MotivoPrivado',
        password: 'ClavePrivada', antes: { rol: 'Médico', password: 'HashPrivado' }, habilitaciones: { habilitadas: Array.from({ length: 101 }, (_, i) => String(i + 1)) } },
      metadatos_seguros: { archivo: 'DocumentoPrivado', token: 'TokenPrivado' }, causa_codigo: 'CausaPrivada' }; mount();
    await screen.findByRole('region', { name: 'Cambios registrados' });
    expect(screen.getByText('Teléfono', { selector: 'li' })).toBeInTheDocument();
    expect(screen.queryByText('Especialidades habilitadas (IDs)')).not.toBeInTheDocument();
    for (const secreto of ['ReferenciaPrivada', 'CampoPrivado', 'MotivoPrivado', 'ClavePrivada', 'HashPrivado', 'DocumentoPrivado', 'TokenPrivado', 'CausaPrivada']) {
      expect(screen.queryByText(new RegExp(secreto))).not.toBeInTheDocument();
    }
  });
  it.each([2, null])('formato %s conserva cabecera pero no interpreta información adicional', async version => {
    current = { ...base, version_formato: version, referencias: { id_medico: '77' }, cambios_seguros: { campos: ['telefono'] },
      metadatos_seguros: { metodo: 'SEEDER' } }; mount();
    await screen.findByText(/Esta versión de formato/);
    expect(screen.getByRole('region', { name: `Evento #${id}` })).toHaveTextContent('CUENTA_ROL_CAMBIADO');
    expect(screen.queryByText('Teléfono', { selector: 'li' })).not.toBeInTheDocument();
    expect(screen.queryByText('ID de Médico', { selector: 'dt' })).not.toBeInTheDocument();
    expect(screen.queryByText('Instalación mediante seeder')).not.toBeInTheDocument();
  });
  it.each(['0', '01', 'no-valido', '9223372036854775808'])('ID %s no consulta HTTP y permite regresar', async invalid => {
    mount(`${root}/${invalid}`); await screen.findByRole('heading', { name: 'Evento no disponible' });
    expect(calls).toHaveLength(0); expect(screen.getByRole('link', { name: 'Volver a Bitácora' })).toHaveAttribute('href', root);
  });
  it.each([404, 422])('%s indica evento no disponible, sin reintento ni datos anteriores', async status => {
    respond = config => { throw fallo(config, status); }; mount(); await screen.findByRole('alert');
    expect(screen.getByRole('alert')).toHaveTextContent(status === 404 ? 'El evento no está disponible' : 'El identificador del evento no es válido');
    expect(screen.queryByRole('button', { name: 'Volver a consultar' })).not.toBeInTheDocument();
    expect(screen.queryByText(/SQL privado/)).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Cambios registrados' })).not.toBeInTheDocument(); expect(calls).toHaveLength(1);
  });
  it.each([403, 419, 500, undefined])('%s muestra error seguro y permite consulta manual', async status => {
    respond = config => { throw fallo(config, status); }; mount(); const user = userEvent.setup();
    await screen.findByRole('alert'); expect(screen.queryByText(/SQL privado/)).not.toBeInTheDocument();
    if (!status) expect(screen.getByRole('alert')).toHaveTextContent('No pudimos consultar el evento');
    expect(calls).toHaveLength(1); respond = config => response(config, { data: current });
    await user.click(screen.getByRole('button', { name: 'Volver a consultar' }));
    await screen.findByRole('region', { name: `Evento #${id}` }); expect(calls).toHaveLength(2);
  });
  it('401 retira el detalle y solicita nuevo login', async () => {
    respond = config => { throw fallo(config, 401); }; mount();
    await screen.findByRole('heading', { name: 'Bienvenido de nuevo' });
    expect(screen.queryByRole('region', { name: 'Cambios registrados' })).not.toBeInTheDocument();
  });
  it('429 espera Retry-After sin reintentar por su cuenta', async () => {
    respond = config => { throw fallo(config, 429); }; mount();
    const retry = await screen.findByRole('button', { name: 'Volver a consultar' }); expect(retry).toBeDisabled();
    await waitFor(() => expect(retry).toBeEnabled(), { timeout: 2500 }); expect(calls).toHaveLength(1);
  });
  it('al cambiar ID cancela el detalle anterior y descarta su respuesta tardía', async () => {
    let terminar, signal;
    respond = config => new Promise(resolve => { signal = config.signal; terminar = () => resolve(response(config, { data: { ...base, tipo_evento: 'DETALLE_OBSOLETO' } })); });
    const router = mount(); await screen.findByText('Consultando evento…');
    respond = config => response(config, { data: { ...base, id_evento: '2', tipo_evento: 'DETALLE_ACTUAL' } });
    await act(async () => router.navigate(`${root}/2`)); await screen.findByText('DETALLE_ACTUAL');
    expect(signal.aborted).toBe(true); await act(async () => terminar());
    expect(screen.queryByText('DETALLE_OBSOLETO')).not.toBeInTheDocument();
  });
  it.each(['enlace', 'historial'])('listado → detalle → regreso por %s conserva filtros aplicados, borrador y página y reconsulta', async regreso => {
    const user = userEvent.setup(); const router = mount(root); await screen.findByRole('table');
    await user.type(screen.getByLabelText('Módulo'), 'MEDICOS'); await user.click(screen.getByRole('button', { name: 'Aplicar filtros' }));
    await screen.findByRole('table'); await user.click(screen.getByRole('button', { name: 'Siguiente' })); await screen.findByText('41 eventos · página 2 de 3');
    await user.type(screen.getByLabelText('Módulo'), '_borrador');
    await user.click(screen.getByRole('link', { name: `Ver evento ${id}` })); await screen.findByRole('region', { name: `Evento #${id}` });
    if (regreso === 'enlace') await user.click(screen.getByRole('link', { name: 'Volver a Bitácora' }));
    else await act(async () => router.navigate(-1));
    await screen.findByText('41 eventos · página 2 de 3');
    expect(screen.getByLabelText('Módulo')).toHaveValue('MEDICOS_borrador');
    expect(calls.at(-1).params).toMatchObject({ modulo: 'MEDICOS', page: 2 });
    expect(calls.every(call => call.method === 'get')).toBe(true);
    await user.click(screen.getByRole('link', { name: 'Inicio', exact: true }));
    await user.click(within(screen.getByRole('navigation', { name: 'Navegación principal' })).getByRole('link', { name: 'Bitácora del Sistema' })); await screen.findByRole('table');
    expect(screen.getByLabelText('Módulo')).toHaveValue(''); expect(calls.at(-1).params).toEqual({ page: 1, per_page: '20' });
  });
});
