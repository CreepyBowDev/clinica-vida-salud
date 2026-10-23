import { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, avanzarSesion } from '../../../../api/api';
import { consultarAgenda, consultarCita, consultarHistorialPaciente, consultarMisCitas, listarMedicosAgenda } from '../../services/agenda.service';
import { cita, detalle, pagina } from '../utils/agendaFixtures';

const original = api.defaults.adapter;
let calls, body;
beforeEach(() => {
  calls = []; body = { data: detalle('Paciente') };
  api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {}, data: body }; };
});
afterEach(() => { api.defaults.adapter = original; });

it('selector usa endpoint propio, búsqueda literal y parámetros cerrados con cancelación', async () => {
  body = pagina([{ ...cita.medico, activo: false, correo_contacto: 'NO-SALIR' }]);
  const signal = new AbortController().signal;
  const result = await listarMedicosAgenda({ nombre: ' Ficticio_% ', activo: true, rol: 'Administrador' }, signal);
  expect(calls[0]).toMatchObject({ url: '/agenda/medicos', method: 'get', sessionBound: true, signal, params: { nombre: 'Ficticio_%', page: 1, per_page: 20 } });
  expect(result.data[0].activo).toBe(false); expect(JSON.stringify(result)).not.toContain('NO-SALIR');
});
it('selector omite nombre en blanco y no exige actividad ni horarios actuales', async () => {
  body = pagina([]); await listarMedicosAgenda({ nombre: '  ' });
  expect(calls[0].params).toEqual({ page: 1, per_page: 20 });
});
it('agenda propia omite Médico y fecha para usar valores del servidor; proyecta solo listado', async () => {
  body = pagina([{ ...cita, motivo_breve: 'NO-SALIR', paciente: { ...cita.paciente, telefono: 'NO-SALIR' } }], {
    medico: cita.medico, filtros: { id_medico: cita.medico.id_medico, fecha: '2026-11-01', estado: null } });
  const result = await consultarAgenda({ id_paciente: '99', rol: 'Médico' });
  expect(calls[0].params).toEqual({ page: 1, per_page: 20 });
  expect(result.meta.filtros.fecha).toBe('2026-11-01'); expect(JSON.stringify(result)).not.toContain('NO-SALIR');
});
it('agenda seleccionada conserva BIGINT textual y filtros exactos, sin orden libre', async () => {
  body = pagina([], { medico: cita.medico, filtros: { id_medico: cita.medico.id_medico, fecha: '0001-01-01', estado: 'Cancelada' } });
  await consultarAgenda({ id_medico: cita.medico.id_medico, fecha: '0001-01-01', estado: 'Cancelada', orderBy: 'desc' });
  expect(calls[0].params).toEqual({ id_medico: cita.medico.id_medico, fecha: '0001-01-01', estado: 'Cancelada', page: 1, per_page: 20 });
});
it('Mis citas solo envía período/estado y no toma ID explícito de la ficha', async () => {
  body = pagina([], { paciente: cita.paciente, filtros: { periodo: 'proximas', estado: null } });
  await consultarMisCitas({ id_paciente: '999', id_medico: '3' });
  expect(calls[0]).toMatchObject({ url: '/mis-citas', params: { page: 1, per_page: 20 } });
});
it('historial de ficha usa ruta canónica, período Todas del servidor y no exige CI/cuenta', async () => {
  body = pagina([], { paciente: cita.paciente, filtros: { periodo: 'todas', estado: null } });
  await consultarHistorialPaciente({ id: '31', cuenta_vinculada: true });
  expect(calls[0]).toMatchObject({ url: '/pacientes/31/citas', params: { page: 1, per_page: 20 } });
});
it('historial conserva estado y período independientes, aunque no haya coincidencias', async () => {
  body = pagina([], { paciente: cita.paciente, filtros: { periodo: 'proximas', estado: 'Cancelada' } });
  const result = await consultarMisCitas({ periodo: 'proximas', estado: 'Cancelada' });
  expect(result.data).toEqual([]); expect(calls[0].params).toMatchObject({ periodo: 'proximas', estado: 'Cancelada' });
});
it('página posterior al final conserva total/contexto sin inventar datos', async () => {
  body = pagina([], { current_page: 2, total: 1, paciente: cita.paciente, filtros: { periodo: 'todas', estado: null } });
  const result = await consultarMisCitas({ periodo: 'todas', page: '2' });
  expect(result.meta).toMatchObject({ total: 1, current_page: 2, from: null, to: null });
});
it.each(['Administrador', 'Recepcionista', 'Médico', 'Paciente'])('detalle %s usa ID textual sin query/body y limita su matriz', async rol => {
  body = { data: { ...detalle(rol), diagnostico: 'NO-SALIR', token: 'NO-SALIR', motivo_breve: '0',
    medico: { ...cita.medico, telefono: 'NO-SALIR' }, paciente: { ...cita.paciente, documento_identidad: null, telefono: '70000000', fecha_nacimiento: '1990-01-01', alergias: 'NO-SALIR' } } };
  const result = await consultarCita({ id: cita.id_cita, rol });
  expect(calls[0].url).toBe(`/citas/${cita.id_cita}`); expect(calls[0].params).toBeUndefined(); expect(calls[0].data).toBeUndefined();
  expect(result.id_cita).toBe(cita.id_cita); expect(JSON.stringify(result)).not.toContain('NO-SALIR');
  expect(Object.hasOwn(result, 'motivo_breve')).toBe(rol !== 'Administrador');
  expect(Object.hasOwn(result.paciente, 'telefono')).toBe(rol === 'Recepcionista');
  expect(Object.hasOwn(result.paciente, 'fecha_nacimiento')).toBe(rol === 'Médico');
});
it.each(['Administrador', 'Recepcionista', 'Médico', 'Paciente'])('contrato exacto %s admite omisión o null según autorización', async rol => {
  body = { data: detalle(rol) };
  if (rol !== 'Administrador') body.data.motivo_breve = null;
  if (rol === 'Médico') body.data.paciente.fecha_nacimiento = null;
  const result = await consultarCita({ id: cita.id_cita, rol });
  if (rol !== 'Administrador') expect(result.motivo_breve).toBeNull();
  if (rol === 'Recepcionista') expect(result.paciente.documento_identidad).toBeNull();
});
it.each(['0', '01', '-1', '1/ruta', '9223372036854775808', 17, null])('ID inválido %s se rechaza antes de HTTP', async id => {
  await expect(consultarCita({ id, rol: 'Paciente' })).rejects.toThrow();
  await expect(consultarHistorialPaciente({ id })).rejects.toThrow();
  expect(calls).toHaveLength(0);
});
it.each([{ page: ['1'] }, { page: 0 }, { per_page: 101 }, { page: '1e2' }, { estado: ['Programada'] }, { fecha: '2026-02-30' }, { id_medico: null }])('agenda no envía entradas inválidas %j', async filtros => {
  await expect(consultarAgenda(filtros)).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each([null, { data: null }, { data: {} }, { data: { ...cita, id_cita: '1' } }, { data: { ...cita, fin_at: cita.inicio_at } },
  { data: { ...cita, paciente: null } }, { data: { ...cita, inicio_at: '2026-02-30T12:00:00.000000Z' } }, { data: detalle('Administrador') }])('detalle malformado se rechaza con mensaje seguro', async value => {
  body = value; await expect(consultarCita({ id: cita.id_cita, rol: 'Paciente' })).rejects.toThrow('No se pudo verificar');
});
it.each(['zona', 'contexto', 'filtro', 'filas', 'total', 'links'])('agenda con %s incongruente no se publica', async change => {
  body = pagina([cita], { medico: cita.medico, filtros: { id_medico: cita.medico.id_medico, fecha: '2026-11-01', estado: null } });
  if (change === 'zona') body.meta.zona_horaria = 'UTC';
  if (change === 'contexto') body.meta.medico.id_medico = '88';
  if (change === 'filtro') body.meta.filtros.estado = 'Cancelada';
  if (change === 'filas') body.data = [];
  if (change === 'total') body.meta.total = -1;
  if (change === 'links') body.links = null;
  await expect(consultarAgenda()).rejects.toThrow('No se pudo verificar');
});
it('historial de otra ficha en una respuesta 200 no se acepta', async () => {
  body = pagina([cita], { paciente: cita.paciente, filtros: { periodo: 'todas', estado: null } });
  await expect(consultarHistorialPaciente({ id: '777' })).rejects.toThrow();
});
it('error HTTP no provoca POST, renovación CSRF ni reintento automático', async () => {
  api.defaults.adapter = vi.fn(async config => { throw new AxiosError('SQL PRIVADO', undefined, config, null, { status: 419 }); });
  await expect(consultarCita({ id: cita.id_cita, rol: 'Paciente' })).rejects.toMatchObject({ response: { status: 419 } });
  expect(api.defaults.adapter).toHaveBeenCalledTimes(1);
});
it('respuesta tardía de sesión sustituida es descartada por Axios existente', async () => {
  let complete;
  api.defaults.adapter = config => new Promise(resolve => { complete = () => resolve({ config, status: 200, data: body }); });
  const pending = consultarCita({ id: cita.id_cita, rol: 'Paciente' });
  await vi.waitFor(() => expect(complete).toBeTypeOf('function'));
  avanzarSesion(); complete();
  await expect(pending).rejects.toMatchObject({ code: 'SESSION_SUPERSEDED' });
});
