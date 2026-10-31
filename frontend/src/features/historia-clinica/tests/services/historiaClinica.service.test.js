import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api } from '../../../../api/api';
import { consultarHistoria, consultarConsulta, consultarVersiones } from '../../services/historiaClinica.service';
import { detalle, historia, meta, paciente, version } from '../utils/clinicaFixtures';

const original = api.defaults.adapter;
let calls, result;
beforeEach(() => {
  calls = []; result = historia();
  api.defaults.adapter = vi.fn(async config => { calls.push(config); return { config, status: 200, headers: {}, data: result }; });
});
afterEach(() => { api.defaults.adapter = original; });

it.each(['Administrador', 'Médico'])('%s lee historia con proyección explícita y BIGINT intacto', async rol => {
  result.data.paciente.diagnostico = 'NO-CONSERVAR'; result.data.consultas[0].extra = 'NO-CONSERVAR';
  const signal = new AbortController().signal;
  const data = await consultarHistoria({ id: paciente.id_paciente, rol }, signal);
  expect(data.paciente.id_paciente).toBe('9007199254740993'); expect(data.consultas[0].inicio_at).toBe('2026-10-30T02:30:00.123456Z');
  expect(JSON.stringify(data)).not.toContain('NO-CONSERVAR');
  expect(calls[0]).toMatchObject({ url: `/pacientes/${paciente.id_paciente}/historia-clinica`, method: 'get', params: { page: 1, per_page: 20 }, sessionBound: true, signal });
});
it('Paciente resuelve titular por perfil y elimina autoría interna, motivos y origen', async () => {
  api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: config.url === '/mi-perfil' ? { data: { ...paciente, historia: 'NO-CONSERVAR' } } : result }; };
  const data = await consultarHistoria({ propia: true, rol: 'Paciente' });
  expect(calls.map(c => c.url)).toEqual(['/mi-perfil', `/pacientes/${paciente.id_paciente}/historia-clinica`]);
  expect(data.antecedentes).not.toHaveProperty('id_autor'); expect(data.antecedentes).not.toHaveProperty('motivo_cambio');
  expect(data.antecedentes).not.toHaveProperty('id_consulta_origen'); expect(JSON.stringify(data)).not.toContain('NO-CONSERVAR');
});
it('aborta entre perfil e historia sin consultar un titular tardío', async () => {
  const controller = new AbortController();
  api.defaults.adapter = async config => { calls.push(config); controller.abort(); return { config, status: 200, data: { data: paciente } }; };
  await expect(consultarHistoria({ propia: true, rol: 'Paciente' }, controller.signal)).rejects.toThrow(); expect(calls).toHaveLength(1);
});
it.each(['Recepcionista', 'Paciente'])('%s no usa selector de historia ni históricos', async rol => {
  await expect(consultarHistoria({ id: paciente.id_paciente, rol })).rejects.toThrow();
  await expect(consultarVersiones({ id: '41', tipo: 'consulta', rol })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['0', '01', '9223372036854775808', '1/query', 41])('ID inválido %s no envía HTTP', async id => {
  await expect(consultarConsulta({ id, rol: 'Médico' })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each([0, -1, 1.5, 2147483648, '2'])('página inválida %s no consulta', async page => {
  await expect(consultarHistoria({ id: paciente.id_paciente, rol: 'Administrador', page })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Administrador', 'Médico', 'Paciente'])('%s lee detalle sin contexto editable, URLs ni extras', async rol => {
  result = detalle(); result.data.estudios[0].url = 'NO-CONSERVAR';
  const data = await consultarConsulta({ id: '41', rol });
  expect(data.meta.total).toBe(1); expect(data.consulta.iniciada_en).toBeNull(); expect(data).not.toHaveProperty('edicion');
  expect(JSON.stringify(data)).not.toContain('NO-CONSERVAR');
  expect(data.consulta.version_vigente).toHaveProperty('diagnostico', null);
  expect(Object.hasOwn(data.consulta.version_vigente, 'id_autor')).toBe(rol !== 'Paciente');
});
it.each(['consulta', 'antecedentes'])('histórico %s usa endpoint real y meta propia', async tipo => {
  result = { data: [version(tipo)], meta: meta() };
  const data = await consultarVersiones({ id: '41', rol: 'Médico', tipo });
  expect(data.versiones[0].id_autor).toBe('50'); expect(data.meta.total).toBe(1);
  expect(calls[0].url).toBe(tipo === 'consulta' ? '/citas/41/consulta/versiones' : '/pacientes/41/historia-clinica/antecedentes/versiones');
});
it('proyecta ausencias legítimas y página vacía fuera de rango', async () => {
  result = { data: { paciente, antecedentes: null, consultas: [] }, meta: meta(0, 2) };
  const data = await consultarHistoria({ id: paciente.id_paciente, rol: 'Médico', page: 2 });
  expect(data.antecedentes).toBeNull(); expect(data.consultas).toEqual([]);
});
it.each([
  d => { d.data.paciente.id_paciente = '9'; },
  d => { d.meta.zona_horaria = 'UTC'; },
  d => { d.meta.total = 2; },
  d => { d.data.consultas[0].inicio_at = '2026-02-30T00:00:00.000000Z'; },
  d => { delete d.data.antecedentes.alergias; },
  d => { d.data.antecedentes.id_version_antecedente = 9007199254740996; },
  d => { d.data.consultas[0].tipo_consulta = 'Borrador'; },
])('rechaza historia 200 malformada sin confundirla con vacío %#', async cambiar => {
  result = structuredClone(historia()); cambiar(result);
  await expect(consultarHistoria({ id: paciente.id_paciente, rol: 'Administrador' })).rejects.toThrow('No se pudo verificar');
});
it('rechaza estado interno de estudio para Paciente', async () => {
  result = detalle(); result.data.estudios[0].estado = 'En revisión';
  await expect(consultarConsulta({ id: '41', rol: 'Paciente' })).rejects.toThrow();
});
