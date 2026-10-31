import { afterEach, beforeEach, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { consultarConsulta } from '../../services/historiaClinica.service';
import { actualizarConsulta, prepararCorreccion } from '../../services/consultaMedica.service';
import { correccionConfirmada, detalle } from '../utils/clinicaFixtures';

const original = api.defaults.adapter;
let calls, raw, respond;
const response = (config, data, status = 200) => ({ config, data, status, headers: {} });
beforeEach(() => {
  calls = []; raw = detalle();
  respond = config => config.url === csrfURL ? response(config, null, 204) : config.method === 'patch'
    ? response(config, correccionConfirmada(JSON.parse(config.data), raw)) : response(config, raw);
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; });
const entrada = async () => ({ id: '41', rol: 'Administrador', idActor: '7', contexto: await consultarConsulta({ id: '41', rol: 'Administrador', paraRegistro: true }),
  consulta: { diagnostico: 'Diagnóstico ficticio corregido' }, motivo_cambio: 'Motivo ficticio de corrección', declaracion: true });

it.each(['Administrador', 'Médico'])('%s corrige la misma consulta por PATCH preservando programación y autor real', async rol => {
  const e = await entrada(); e.rol = rol; const r = await actualizarConsulta(e);
  expect(r.modificado).toBe(true); expect(r.consulta.id_consulta).toBe(e.contexto.consulta.id_consulta); expect(r.consulta.version_vigente.numero_version).toBe(2);
  expect(r.consulta.version_vigente.id_autor).toBe('7'); expect(r.cita.estado).toBe('Programada'); expect(calls.filter(c => c.method === 'post')).toHaveLength(0);
  expect(calls.at(-1)).toMatchObject({ url: '/citas/41/consulta', method: 'patch', sessionBound: true });
  const p = JSON.parse(calls.at(-1).data); expect(p.originales).toEqual(e.contexto.edicion.originales); expect(p.consulta).toEqual(e.consulta);
});
it('solo antecedentes no modifica la versión de consulta ni crea otra consulta', async () => {
  const e = await entrada(); e.consulta = {}; e.antecedentes = { medicamentos: null, alergias: 'Alergia ficticia' };
  const r = await actualizarConsulta(e); expect(r.consulta.version_vigente).toEqual(e.contexto.consulta.version_vigente);
  expect(r.antecedentes.numero_version).toBe(2); expect(r.antecedentes.id_consulta_origen).toBe('31'); expect(r.antecedentes.medicamentos).toBeNull();
  expect(JSON.parse(calls.at(-1).data)).not.toHaveProperty('consulta');
});
it('corrige ambos bloques con snapshots separados y sin mutar original consultado', async () => {
  const saved = structuredClone(raw), e = await entrada(); e.antecedentes = { alergias: 'Dato ficticio' };
  const r = await actualizarConsulta(e); expect(r.consulta.version_vigente.numero_version).toBe(2); expect(r.antecedentes.numero_version).toBe(2); expect(raw).toEqual(saved);
});
it('primeros antecedentes tras una consulta existente llevan versión 1', async () => {
  raw.data.antecedentes = null; raw.data.edicion.originales.id_version_antecedente = null;
  const e = await entrada(); e.consulta = {}; e.antecedentes = { antecedentes_relevantes: 'Primer antecedente ficticio' };
  const r = await actualizarConsulta(e); expect(r.antecedentes.numero_version).toBe(1); expect(r.antecedentes.id_autor).toBe('7');
});
it('no-op sin motivo se envía con originales y devuelve sin nueva versión', async () => {
  const e = await entrada(); e.consulta = { tipo_consulta: 'Control' }; e.motivo_cambio = '';
  const r = await actualizarConsulta(e); expect(r.modificado).toBe(false); expect(r.consulta.version_vigente).toEqual(e.contexto.consulta.version_vigente);
  expect(JSON.parse(calls.at(-1).data)).not.toHaveProperty('motivo_cambio'); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it('no-op sobre todo desconocido no crea antecedentes ni exige motivo', async () => {
  raw.data.antecedentes = null; raw.data.edicion.originales.id_version_antecedente = null;
  const e = await entrada(); e.consulta = {}; e.antecedentes = { alergias: null }; delete e.motivo_cambio;
  const r = await actualizarConsulta(e); expect(r.modificado).toBe(false); expect(r.antecedentes).toBeNull();
});
it('no normaliza espacios/saltos ni usa tipo/códigos/actores suministrados fuera de allowlist', async () => {
  const e = await entrada(); e.consulta = { observaciones: ' Corrección ficticia\n', id_autor: '9' }; e.motivo_cambio = ' Motivo ficticio\n'; e.rol_solicitado = 'Médico';
  const p = prepararCorreccion(e); expect(p.consulta).toEqual({ observaciones: ' Corrección ficticia\n' }); expect(p.motivo_cambio).toBe(' Motivo ficticio\n');
  expect(Object.keys(p).sort()).toEqual(['atencion_real_verificada', 'consulta', 'motivo_cambio', 'originales']); await actualizarConsulta(e);
});
it.each(['', '   ', null, 'm'.repeat(2001)])('motivo inválido no envía corrección %#', async motivo_cambio => {
  const e = await entrada(); calls = []; await expect(actualizarConsulta({ ...e, motivo_cambio })).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(0);
});
it.each([{ tipo_consulta: null }, { motivo_consulta: null }, { tipo_consulta: 'Otro' }, { diagnostico: 'x'.repeat(10001) }])('contenido inválido no se envía %#', async consulta => {
  const e = await entrada(); calls = []; await expect(actualizarConsulta({ ...e, consulta })).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(0);
});
it.each(['Recepcionista', 'Paciente'])('%s no envía PATCH', async rol => {
  const e = await entrada(); calls = []; await expect(actualizarConsulta({ ...e, rol })).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(0);
});
it('declaración falsa o contexto sin autorización no envía PATCH', async () => {
  const e = await entrada(); calls = []; await expect(actualizarConsulta({ ...e, declaracion: false })).rejects.toMatchObject({ consultaEnviada: false });
  e.contexto.edicion.acciones.actualizar = false; await expect(actualizarConsulta(e)).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(0);
});
it('fallo CSRF no envía PATCH', async () => {
  const e = await entrada(); calls = []; respond = config => Promise.reject(new AxiosError('NO-MOSTRAR', undefined, config));
  await expect(actualizarConsulta(e)).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(1);
});
it('original obsoleto se rechaza aunque contenido sea no-op; no hay replay', async () => {
  const e = await entrada(); e.consulta = {}; delete e.motivo_cambio;
  const normal = respond; respond = config => config.method === 'patch' ? Promise.reject(new AxiosError('OBSOLETO', undefined, config, null, { status: 409, data: { error: { code: 'CLINICA_EDICION_OBSOLETA' } } })) : normal(config);
  await expect(actualizarConsulta(e)).rejects.toMatchObject({ consultaEnviada: true, response: { status: 409 } }); expect(calls.filter(c => c.method === 'patch')).toHaveLength(1);
});
it.each([
  d => { d.data.modificado = false; },
  d => { d.data.consulta.version_vigente.id_autor = '99'; },
  d => { d.data.consulta.version_vigente.numero_version = 1; },
  d => { d.data.consulta.version_vigente.id_version_consulta = '9007199254740995'; },
  d => { d.data.consulta.id_consulta = '32'; },
  d => { d.data.cita.estado = 'Atendida'; },
  d => { delete d.data.modificado; },
])('200 incoherente es incierto y nunca éxito %#', async cambiar => {
  const e = await entrada(); const normal = respond; respond = config => { if (config.method !== 'patch') return normal(config); const d = correccionConfirmada(JSON.parse(config.data), raw); cambiar(d); return response(config, d); };
  await expect(actualizarConsulta(e)).rejects.toMatchObject({ consultaEnviada: true });
});
it('respuesta no-op que cambia snapshot anterior se rechaza', async () => {
  const e = await entrada(); e.consulta = {}; const normal = respond;
  respond = config => { if (config.method !== 'patch') return normal(config); const d = correccionConfirmada(JSON.parse(config.data), raw); d.data.consulta.version_vigente.id_autor = '99'; return response(config, d); };
  await expect(actualizarConsulta(e)).rejects.toMatchObject({ consultaEnviada: true });
});
