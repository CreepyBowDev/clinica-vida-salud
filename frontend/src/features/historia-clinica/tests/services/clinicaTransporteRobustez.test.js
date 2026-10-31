import { afterEach, beforeEach, expect, it } from 'vitest';
import { api, avanzarSesion, csrfURL, escucharSesionInvalidada } from '../../../../api/api';
import { consultarConsulta, consultarHistoria, consultarVersiones } from '../../services/historiaClinica.service';
import { AxiosError } from 'axios';
import { registrarConsulta, actualizarConsulta } from '../../services/consultaMedica.service';
import { altaConfirmada, contextoAlta, correccionConfirmada, detalle } from '../utils/clinicaFixtures';

const original = api.defaults.adapter;
let calls, responder;
const response = (config, data, status = 200) => ({ config, data, status, headers: {} });
beforeEach(() => {
  calls = []; responder = config => response(config, contextoAlta());
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = original; });
it.each(['post', 'patch'])('sesión cambiada al despachar %s no llega al adaptador con contexto anterior', async method => {
  responder = config => response(config, method === 'post' ? contextoAlta() : detalle());
  const contexto = await consultarConsulta({ id: '41', rol: 'Médico', paraRegistro: true });
  const escribir = method === 'post' ? registrarConsulta : actualizarConsulta;
  const interceptor = api.interceptors.request.use(config => { if (config.method === method) avanzarSesion(); return config; });
  responder = config => config.url === csrfURL ? response(config, null, 204) : response(config, method === 'post' ? altaConfirmada(JSON.parse(config.data)) : correccionConfirmada(JSON.parse(config.data)), method === 'post' ? 201 : 200);
  try {
    await expect(escribir({ id: '41', rol: 'Médico', idActor: '7', contexto, consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' }, motivo_cambio: 'Corrección ficticia', declaracion: true })).rejects.toThrow();
    expect(calls.filter(c => c.method === method)).toHaveLength(0);
  } finally { api.interceptors.request.eject(interceptor); }
});
it.each(['post', 'patch'])('contexto %s de una época anterior no obtiene CSRF ni se envía', async method => {
  responder = config => response(config, method === 'post' ? contextoAlta() : detalle());
  const contexto = await consultarConsulta({ id: '41', rol: 'Médico', paraRegistro: true }); avanzarSesion(); calls = [];
  await expect((method === 'post' ? registrarConsulta : actualizarConsulta)({ id: '41', rol: 'Médico', idActor: '7', contexto, declaracion: true, consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' }, motivo_cambio: 'Motivo ficticio' })).rejects.toMatchObject({ consultaEnviada: false });
  expect(calls).toHaveLength(0);
});
it.each(['post', 'patch'])('respuesta %s de época sustituida se descarta aunque sea coherente', async method => {
  responder = config => response(config, method === 'post' ? contextoAlta() : detalle());
  const contexto = await consultarConsulta({ id: '41', rol: 'Médico', paraRegistro: true });
  responder = config => { if (config.url === csrfURL) return response(config, null, 204); const payload = JSON.parse(config.data); avanzarSesion();
    return response(config, method === 'post' ? altaConfirmada(payload) : correccionConfirmada(payload), method === 'post' ? 201 : 200); };
  await expect((method === 'post' ? registrarConsulta : actualizarConsulta)({ id: '41', rol: 'Médico', idActor: '7', contexto, declaracion: true,
    consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' }, motivo_cambio: 'Motivo ficticio' })).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED', consultaEnviada: true });
  expect(calls.filter(c => c.method === method)).toHaveLength(1);
});
it.each([401, 403, 409, 419, 422, 429, 500])('error tardío %s no invalida la nueva sesión ni conserva cuerpo anterior', async status => {
  let invalidaciones = 0; const unsubscribe = escucharSesionInvalidada(() => invalidaciones++);
  responder = config => { avanzarSesion(); return Promise.reject(new AxiosError('SQL-PRIVADO', undefined, config, null, { status, data: { error: { message: 'SQL-PRIVADO' } }, headers: {} })); };
  try { await expect(consultarConsulta({ id: '41', rol: 'Médico' })).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' }); expect(invalidaciones).toBe(0); }
  finally { unsubscribe(); }
});
it('perfil propio e historia se ligan a una sola época; cambio intermedio no consulta otra ficha', async () => {
  const interceptor = api.interceptors.request.use(config => { if (config.url.includes('/historia-clinica')) avanzarSesion(); return config; });
  responder = config => response(config, { data: { id_paciente: '9007199254740993' } });
  try { await expect(consultarHistoria({ rol: 'Paciente', propia: true })).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' }); expect(calls.map(c => c.url)).toEqual(['/mi-perfil']); }
  finally { api.interceptors.request.eject(interceptor); }
});
it.each(['consulta', 'antecedentes'])('histórico %s también rechaza respuesta de sesión anterior', async tipo => {
  responder = config => { avanzarSesion(); return response(config, { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1, zona_horaria: 'America/La_Paz' } }); };
  await expect(consultarVersiones({ id: '41', rol: 'Médico', tipo })).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
});
it.each(['post', 'patch'])('abortar durante CSRF de %s nunca despacha escritura', async method => {
  responder = config => response(config, method === 'post' ? contextoAlta() : detalle());
  const contexto = await consultarConsulta({ id: '41', rol: 'Médico', paraRegistro: true }), controller = new AbortController();
  responder = config => { controller.abort(); return response(config, null, 204); };
  await expect((method === 'post' ? registrarConsulta : actualizarConsulta)({ id: '41', rol: 'Médico', idActor: '7', contexto, declaracion: true,
    consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' }, motivo_cambio: 'Motivo ficticio' }, controller.signal)).rejects.toMatchObject({ consultaEnviada: false });
  expect(calls.some(c => c.method === method)).toBe(false);
});
it.each(['id_autor', 'id_consulta_origen', 'numero_version'])('alta con antecedente incoherente en %s es incierta', async campo => {
  const contexto = await consultarConsulta({ id: '41', rol: 'Médico', paraRegistro: true });
  responder = config => { if (config.url === csrfURL) return response(config, null, 204); const d = altaConfirmada(JSON.parse(config.data)); d.data.antecedentes[campo] = campo === 'numero_version' ? 1 : '999'; return response(config, d, 201); };
  await expect(registrarConsulta({ id: '41', rol: 'Médico', idActor: '7', contexto, declaracion: true, consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' }, antecedentes: { alergias: 'Dato ficticio' } })).rejects.toMatchObject({ consultaEnviada: true });
});
it('alta sin antecedentes aportados rechaza una respuesta que sustituye su snapshot vigente', async () => {
  const contexto = await consultarConsulta({ id: '41', rol: 'Médico', paraRegistro: true });
  responder = config => { if (config.url === csrfURL) return response(config, null, 204); const d = altaConfirmada(JSON.parse(config.data)); d.data.antecedentes.id_autor = '999'; return response(config, d, 201); };
  await expect(registrarConsulta({ id: '41', rol: 'Médico', idActor: '7', contexto, declaracion: true, consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' } })).rejects.toMatchObject({ consultaEnviada: true });
});
it('metadato local de época nunca se incluye en el cuerpo clínico', async () => {
  const contexto = await consultarConsulta({ id: '41', rol: 'Médico', paraRegistro: true }); expect(Number.isInteger(contexto.epocaSesion)).toBe(true);
  responder = config => config.url === csrfURL ? response(config, null, 204) : response(config, altaConfirmada(JSON.parse(config.data)), 201);
  await registrarConsulta({ id: '41', rol: 'Médico', idActor: '7', contexto, declaracion: true, consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' } });
  const payload = JSON.parse(calls.find(c => c.method === 'post').data); expect(payload).not.toHaveProperty('epocaSesion'); expect(payload.originales).not.toHaveProperty('epocaSesion');
});
