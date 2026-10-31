import { afterEach, beforeEach, expect, it } from 'vitest';
import { api } from '../../../../api/api';
import { consultarContextoGestion, consultarGestionCita } from '../../services/gestionCita.service';
import { cita, detalle, gestion } from '../utils/gestionFixtures';

const original = api.defaults.adapter;
let calls, body, detail;
beforeEach(() => {
  calls = []; body = gestion(); detail = detalle('Administrador');
  api.defaults.adapter = async config => { calls.push(config); return { config, status: 200, headers: {},
    data: { data: config.url.endsWith('/gestion') ? body : detail } }; };
});
afterEach(() => { api.defaults.adapter = original; });

it('GET exacto, con sesión/cancelación, BIGINT textual y microsegundos intactos, sin query/cuerpo', async () => {
  const signal = new AbortController().signal;
  const result = await consultarGestionCita({ id: cita.id_cita, rol: 'Paciente', query: 'NO-SALIR' }, signal);
  expect(calls).toHaveLength(1); expect(calls[0]).toMatchObject({ method: 'get', url: `/citas/${cita.id_cita}/gestion`, sessionBound: true, signal });
  expect(calls[0].params).toBeUndefined(); expect(calls[0].data).toBeUndefined();
  expect(result).toEqual(gestion()); expect(result.actualizada_en).toBe('2026-10-26T12:00:00.123456Z');
});
it('proyección mínima descarta secretos/extras incluso en acciones y último cambio', async () => {
  body = { ...body, motivo: 'NO-SALIR', diagnostico: 'NO-SALIR', revision_turno: 'NO-SALIR',
    acciones: { ...body.acciones, editar: 'NO-SALIR' }, ultimo_cambio: { id_cambio_cita: '4', id_actor: '9223372036854775807',
      operacion: 'CANCELACION', realizado_en: body.actualizada_en, motivo: 'NO-SALIR' } };
  const result = await consultarGestionCita({ id: cita.id_cita });
  expect(JSON.stringify(result)).not.toContain('NO-SALIR'); expect(result.ultimo_cambio.id_actor).toBe('9223372036854775807');
});
it.each(['Administrador', 'Recepcionista'])('%s combina solo nombres con IDs verificados, programación siempre desde gestión', async rol => {
  detail.inicio_at = '2026-12-01T12:00:00.000000Z'; detail.fin_at = '2026-12-01T12:15:00.000000Z';
  const result = await consultarContextoGestion({ id: cita.id_cita, rol });
  expect(result.gestion.inicio_at).toBe(body.inicio_at); expect(result.paciente).toEqual({ nombres: 'Paciente', apellidos: 'De prueba' });
  expect(JSON.stringify(result)).not.toContain('70000000'); expect(JSON.stringify(result)).not.toContain('MOTIVO-FICTICIO');
  expect(calls).toHaveLength(2); expect(calls.every(call => call.method === 'get' && call.sessionBound)).toBe(true);
});
it.each(['0', '01', '-1', '9223372036854775808', 17, null, undefined, '1/gestion'])('ID inválido %s no envía HTTP', async id => {
  await expect(consultarGestionCita({ id })).rejects.toThrow('No se pudo verificar'); expect(calls).toHaveLength(0);
});
it.each(['Paciente', 'Médico', undefined])('contexto de rol %s no consulta datos', async rol => {
  await expect(consultarContextoGestion({ id: cita.id_cita, rol })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['id_paciente', 'id_medico', 'id_especialidad', 'codigo_cita'])('no publica etiquetas de detalle con %s distinto', async key => {
  if (key === 'codigo_cita') detail.codigo_cita = 'OTRA-CITA';
  else detail[{ id_paciente: 'paciente', id_medico: 'medico', id_especialidad: 'especialidad' }[key]][key] = '88';
  await expect(consultarContextoGestion({ id: cita.id_cita, rol: 'Administrador' })).rejects.toThrow();
});
it.each(['CITA_GESTION_NO_PERMITIDA', 'CITA_PLAZO_GESTION_FINALIZADO', 'CITA_CON_ATENCION_DOCUMENTADA'])('impedimento %s se conserva sin deducir atención terminada', async code => {
  body.acciones = { reprogramar: false, cancelar: false }; body.impedimentos = [code];
  const result = await consultarGestionCita({ id: cita.id_cita }); expect(result.impedimentos).toEqual([code]);
});
it.each([
  ['cuerpo ausente', null], ['ID distinto', { id_cita: '7' }], ['ID numérico', { id_medico: 7 }],
  ['estado inexistente', { estado: 'Suspendida' }], ['modalidad virtual', { modalidad: 'Virtual' }], ['duración cero', { duracion_minutos: 0 }],
  ['versión cadena', { version_programacion: '1' }], ['versión desbordada', { version_programacion: 2147483648 }],
  ['timestamp sin microsegundos', { actualizada_en: '2026-10-26T12:00:00Z' }], ['día imposible', { inicio_at: '2026-02-30T12:00:00.000000Z' }],
  ['intervalo inverso', { fin_at: '2026-01-01T12:00:00.000000Z' }], ['zona diferente', { zona_horaria: 'UTC' }],
  ['consulta ausente', { consultado_en: undefined }], ['acciones ausentes', { acciones: null }], ['acción no booleana', { acciones: { reprogramar: 'true', cancelar: true } }],
  ['impedimento desconocido', { impedimentos: ['SQL-PRIVADO'] }], ['acciones incoherentes', { impedimentos: ['CITA_CON_ATENCION_DOCUMENTADA'] }],
  ['cambio ausente', { ultimo_cambio: undefined }], ['cambio inválido', { ultimo_cambio: { id_actor: '01' } }], ['sede vacía', { sede_atencion: '' }],
])('%s rechaza respuesta malformada con error seguro', async (_, patch) => {
  body = patch === null ? null : { ...body, ...patch };
  await expect(consultarGestionCita({ id: cita.id_cita })).rejects.toThrow('No se pudo verificar la gestión de la cita.');
});
