import { afterEach, beforeEach, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { consultarConsulta } from '../../services/historiaClinica.service';
import { prepararAlta, registrarConsulta } from '../../services/consultaMedica.service';
import { altaConfirmada, contextoAlta } from '../utils/clinicaFixtures';

const original = api.defaults.adapter;
let calls, respond;
const response = (config, data, status = 200) => ({ config, data, status, headers: {} });
beforeEach(() => {
  calls = [];
  respond = config => config.url === csrfURL ? response(config, null, 204) : config.method === 'post'
    ? response(config, altaConfirmada(JSON.parse(config.data)), 201) : response(config, contextoAlta());
  api.defaults.adapter = async config => { calls.push(config); return respond(config); };
});
afterEach(() => { api.defaults.adapter = original; });
const context = () => consultarConsulta({ id: '41', rol: 'Administrador', paraRegistro: true });
const entrada = async () => ({ id: '41', rol: 'Administrador', idActor: '7', contexto: await context(), consulta: { tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' }, declaracion: true });

it('contexto de registro valida y conserva originales exactos, sin extras', async () => {
  const c = await context(); expect(c.edicion.originales).toEqual({ version_programacion: 1, actualizada_en: '2026-10-30T02:30:00.123456Z',
    id_consulta: null, id_version_consulta: null, id_version_antecedente: '9007199254740996' });
  expect(JSON.stringify(c)).not.toContain('NO-CONSERVAR'); expect(calls[0].params).toEqual({ page: 1, per_page: 20 });
});
it('médico relacionado sin contexto editable conserva lectura sin inventar permiso', async () => {
  const d = contextoAlta(); delete d.data.edicion; respond = config => response(config, d);
  expect((await context()).edicion).toBeNull();
});
it.each([
  d => { d.data.edicion.originales.actualizada_en = '2026-10-30T02:30:00.123Z'; },
  d => { d.data.edicion.originales.id_version_antecedente = '9'; },
  d => { d.data.edicion.originales.version_programacion = 2; },
  d => { d.data.edicion.acciones.registrar = false; },
  d => { d.data.edicion.impedimentos.registrar = ['INVENTADO']; },
])('contexto incoherente rechaza antes de habilitar formulario %#', async cambiar => {
  const d = contextoAlta(); cambiar(d); respond = config => response(config, d);
  await expect(context()).rejects.toThrow(); expect(calls.every(c => c.method === 'get')).toBe(true);
});
it.each(['Administrador', 'Médico'])('%s hace un solo POST sin actor/estado/motivo de corrección', async rol => {
  const e = await entrada(); e.rol = rol; e.consulta.estado = 'Atendida'; e.id_paciente = '1'; e.motivo_cambio = 'NO-ENVIAR';
  const r = await registrarConsulta(e); expect(r.consulta.version_vigente.id_autor).toBe('7'); expect(r.cita.estado).toBe('Programada');
  expect(calls.map(c => c.method)).toEqual(['get', 'get', 'post']);
  const p = JSON.parse(calls.at(-1).data); expect(Object.keys(p).sort()).toEqual(['atencion_real_verificada', 'consulta', 'originales']);
  expect(p.consulta).toEqual({ tipo_consulta: 'Control', motivo_consulta: 'Motivo ficticio' });
  expect(calls.at(-1)).toMatchObject({ sessionBound: true, url: '/citas/41/consulta' });
});
it('antecedentes omitidos conservan y null explícito quita; no normaliza texto', async () => {
  const e = await entrada(); e.antecedentes = { medicamentos: null, alergias: ' Dato ficticio\n', id_autor: '999' };
  const p = prepararAlta(e); expect(p.antecedentes).toEqual({ medicamentos: null, alergias: ' Dato ficticio\n' });
  await registrarConsulta(e); expect(JSON.parse(calls.at(-1).data).antecedentes).toEqual(p.antecedentes);
});
it.each(['Recepcionista', 'Paciente'])('%s no obtiene contexto editable ni escribe', async rol => {
  await expect(consultarConsulta({ id: '41', rol, paraRegistro: true })).rejects.toThrow();
  const e = await entrada(); calls = []; e.rol = rol; await expect(registrarConsulta(e)).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(0);
});
it.each([false, 'true', 1, undefined])('declaración %s no envía POST', async declaracion => {
  const e = await entrada(); calls = []; await expect(registrarConsulta({ ...e, declaracion })).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(0);
});
it('fallo CSRF no envía POST y marca explícitamente no enviado', async () => {
  const e = await entrada(); calls = []; respond = config => Promise.reject(new AxiosError('NO-MOSTRAR', undefined, config));
  await expect(registrarConsulta(e)).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(1); expect(calls[0].url).toBe(csrfURL);
});
it('aborto antes de enviar no solicita CSRF ni POST', async () => {
  const e = await entrada(), c = new AbortController(); c.abort(); calls = [];
  await expect(registrarConsulta(e, c.signal)).rejects.toMatchObject({ consultaEnviada: false }); expect(calls).toHaveLength(0);
});
it.each([200, 204])('respuesta %s no confirma alta ni dispara replay', async status => {
  const e = await entrada(); respond = config => config.method === 'post' ? response(config, altaConfirmada(JSON.parse(config.data)), status) : response(config, null, 204);
  await expect(registrarConsulta(e)).rejects.toMatchObject({ consultaEnviada: true }); expect(calls.filter(c => c.method === 'post')).toHaveLength(1);
});
it.each([
  d => { d.data.consulta.version_vigente.id_autor = '99'; },
  d => { d.data.cita.estado = 'Atendida'; },
  d => { d.data.consulta.version_vigente.numero_version = 2; },
  d => { d.data.consulta.version_vigente.diagnostico = 'CAMBIO-INCIERTO'; },
  d => { d.data.cita.actualizada_en = '2026-10-30T02:30:00.123457Z'; },
])('201 incoherente es resultado incierto %#', async cambiar => {
  const e = await entrada(); respond = config => { if (config.method !== 'post') return response(config, null, 204); const d = altaConfirmada(JSON.parse(config.data)); cambiar(d); return response(config, d, 201); };
  await expect(registrarConsulta(e)).rejects.toMatchObject({ consultaEnviada: true });
});
