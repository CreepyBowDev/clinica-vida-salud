import { afterEach, beforeEach, expect, it } from 'vitest';
import { AxiosError } from 'axios';
import { api, csrfURL } from '../../../../api/api';
import { registrarInasistencia, consultarAsistenciaCita } from '../../services/asistenciaCita.service';
import { ausenciaElegible, cita, inasistencia } from '../utils/asistenciaFixtures';

const adapter = api.defaults.adapter;
let calls, saved, responder;
const entrada = patch => ({ id: cita.id_cita, rol: 'Administrador', original: ausenciaElegible(), declaracion: true, idActor: '1', ...patch });
beforeEach(() => {
  calls = []; saved = inasistencia();
  responder = config => ({ config, status: config.url === csrfURL ? 204 : 200, headers: {}, data: { data: saved } });
  api.defaults.adapter = async config => { calls.push(config); return responder(config); };
});
afterEach(() => { api.defaults.adapter = adapter; });

it.each(['Administrador', 'Recepcionista'])('%s envía ausencia true y originales exactos, sin campos de llegada ni otras acciones', async rol => {
  const signal = new AbortController().signal;
  const result = await registrarInasistencia(entrada({ rol, original: ausenciaElegible(rol), presencia_verificada: true,
    atencion_realizada: true, estado: 'Inasistencia', motivo: 'NO-SALIR', id_medico: '88' }), signal);
  expect(calls).toHaveLength(2);
  expect(calls[0]).toMatchObject({ method: 'get', url: csrfURL, signal, sessionBound: true });
  expect(calls[1]).toMatchObject({ method: 'patch', url: `/citas/${cita.id_cita}/inasistencia`, signal, sessionBound: true });
  expect(JSON.parse(calls[1].data)).toEqual({ version_programacion_original: 1,
    actualizada_en_original: ausenciaElegible().actualizada_en, ausencia_verificada: true });
  expect(result.estado).toBe('Inasistencia'); expect(result.version_programacion).toBe(1);
  expect(Object.keys(result).sort()).toEqual(Object.keys(inasistencia()).sort());
});
it('no calcula el fin ni exige el mismo día a partir del reloj local del navegador', async () => {
  const original = ausenciaElegible('Administrador', { consultado_en: '2026-11-03T04:00:00.000000Z' });
  responder = config => ({ config, status: 200, headers: {}, data: { data: original } });
  const result = await consultarAsistenciaCita({ id: cita.id_cita, rol: 'Administrador' });
  expect(result.acciones.registrar_inasistencia).toBe(true); expect(result.estado).toBe('Programada');
});
it('versión máxima y comparación de microsegundos conservan toda la programación', async () => {
  const original = ausenciaElegible('Administrador', { version_programacion: 2147483647, actualizada_en: '2026-11-01T02:45:00.123456Z' });
  saved = inasistencia(original); const result = await registrarInasistencia(entrada({ original }));
  expect(result.actualizada_en).toBe('2026-11-01T02:45:00.123457Z'); expect(result.version_programacion).toBe(2147483647);
  expect(JSON.parse(calls[1].data).actualizada_en_original).toBe(original.actualizada_en);
});
it.each([undefined, false, null, 0, 1, 'true', '1', [], {}])('declaración %s inválida no hace CSRF ni escritura', async declaracion => {
  await expect(registrarInasistencia(entrada({ declaracion }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Paciente', 'Médico', 'Otro', undefined])('rol %s no registra ausencia', async rol => {
  await expect(registrarInasistencia(entrada({ rol }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'])('estado %s no envía aun si se manipula elegibilidad', async estado => {
  await expect(registrarInasistencia(entrada({ original: ausenciaElegible('Administrador', { estado }) }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['CITA_INASISTENCIA_ANTICIPADA', 'CITA_CON_ATENCION_DOCUMENTADA', 'CITA_TRANSICION_NO_PERMITIDA'])('impedimento %s bloquea antes de enviar', async code => {
  const original = ausenciaElegible(); original.acciones.registrar_inasistencia = false; original.impedimentos.registrar_inasistencia = [code];
  await expect(registrarInasistencia(entrada({ original }))).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it.each(['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'id_consultorio', 'codigo_cita', 'inicio_at', 'fin_at', 'duracion_minutos',
  'modalidad', 'sede_atencion', 'consultorio_atencion', 'estado', 'version_programacion', 'actualizada_en', 'ultimo_cambio'])('200 inválido en %s es incierto después de enviar', async campo => {
  saved[campo] = null; await expect(registrarInasistencia(entrada())).rejects.toMatchObject({ asistenciaEnviada: true }); expect(calls).toHaveLength(2);
});
it.each(['id_cita', 'id_paciente', 'id_medico', 'id_consultorio', 'id_especialidad', 'codigo_cita', 'inicio_at', 'fin_at',
  'duracion_minutos', 'sede_atencion', 'consultorio_atencion', 'version_programacion'])('cambio indebido de %s no confirma la escritura', async campo => {
  saved[campo] = typeof saved[campo] === 'number' ? saved[campo] + 1 : campo.endsWith('_at') ? '2026-11-01T12:30:00.000000Z' : '88';
  await expect(registrarInasistencia(entrada())).rejects.toMatchObject({ asistenciaEnviada: true });
});
it.each([['operacion', 'LLEGADA'], ['operacion', 'ATENCION'], ['id_actor', '2'], ['realizado_en', 'sin-fecha']])('historial %s=%s no valida una inasistencia', async (campo, valor) => {
  saved.ultimo_cambio[campo] = valor; await expect(registrarInasistencia(entrada())).rejects.toMatchObject({ asistenciaEnviada: true });
});
it('descarta secretos/extras de respuesta y declaración, sin alterar clínica ni reconstruir avisos en cliente', async () => {
  saved = { ...saved, diagnostico: 'NO-SALIR', ausencia_verificada: true, recordatorios: 'NO-SALIR',
    ultimo_cambio: { ...saved.ultimo_cambio, motivo: 'NO-SALIR' } };
  const result = await registrarInasistencia(entrada()); expect(JSON.stringify(result)).not.toMatch(/NO-SALIR|diagnostico|ausencia_verificada|recordatorios/);
  expect(calls.map(c => c.url)).toEqual([csrfURL, `/citas/${cita.id_cita}/inasistencia`]);
});
it('CSRF fallido o abandono durante preparación nunca envían un PATCH tardío', async () => {
  responder = config => Promise.reject(new AxiosError('PRIVADO', undefined, config));
  await expect(registrarInasistencia(entrada())).rejects.toMatchObject({ asistenciaEnviada: false }); expect(calls).toHaveLength(1);
  calls = []; const controller = new AbortController();
  responder = config => { controller.abort(); return { config, status: 204, headers: {}, data: null }; };
  await expect(registrarInasistencia(entrada(), controller.signal)).rejects.toMatchObject({ asistenciaEnviada: false }); expect(calls).toHaveLength(1);
});
