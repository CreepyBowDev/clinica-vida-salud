import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { listarHorarios, crearHorario, actualizarHorario, cambiarEstadoHorario, consultarImpactoHorario } from '../../services/horario.service';
const original = api.defaults.adapter, id = '9223372036854775807', h = '9007199254740993', version = '2026-10-22T12:00:00.123456Z';
let calls;
beforeEach(() => { calls = []; api.defaults.adapter = vi.fn(async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: { id_horario: h, elementos: [] }, meta: { total: 0 } } }; }); });
afterEach(() => { api.defaults.adapter = original; });
it('listado conserva contrato anidado, filtros y cancelación', async () => {
  const signal = new AbortController().signal;
  const result = await listarHorarios({ id, activo: '0', page: 2, per_page: 10, rol: 'No enviar' }, signal);
  expect(calls[0]).toMatchObject({ url: `/medicos/${id}/horarios`, signal, sessionBound: true, params: { activo: '0', page: 2, per_page: 10 } }); expect(result.data.elementos).toEqual([]);
});
it('alta envía intervalo con segundos y versión sin actividad impuesta', async () => {
  await crearHorario(id, { dia_semana: 7, hora_inicio: '08:00:01', hora_fin: '08:50:01', actualizado_en_original: version, activo: false });
  expect(calls[0].url).toBe(csrfURL); expect(JSON.parse(calls[1].data)).toEqual({ dia_semana: 7, hora_inicio: '08:00:01', hora_fin: '08:50:01', actualizado_en_original: version });
});
it('edición parcial y estado toman ID horario solo de la ruta', async () => {
  await actualizarHorario(id, h, { hora_fin: '12:00:01', actualizado_en_original: version, id_horario: '2', activo: true });
  expect(calls[1].url).toBe(`/medicos/${id}/horarios/${h}`); expect(JSON.parse(calls[1].data)).toEqual({ hora_fin: '12:00:01', actualizado_en_original: version });
  await cambiarEstadoHorario(id, h, { activo: false, actualizado_en_original: version, hora_inicio: '00:00:00' });
  expect(JSON.parse(calls[3].data)).toEqual({ activo: false, actualizado_en_original: version });
});
it.each(['CREAR', 'EDITAR', 'ESTADO'])('impacto %s usa POST cerrado sin huella y repite paginación en cuerpo', async accion => {
  const signal = new AbortController().signal;
  await consultarImpactoHorario(id, { accion, id_horario: h, actualizado_en_original: version, dia_semana: 3, hora_fin: '12:00:00', activo: false, page: 2, per_page: 10, citas: ['1'] }, signal);
  const payload = JSON.parse(calls[1].data);
  expect(calls[1]).toMatchObject({ method: 'post', url: `/medicos/${id}/horarios/impacto`, signal, sessionBound: true });
  expect(payload).toEqual({ accion, actualizado_en_original: version, page: 2, per_page: 10, ...(accion === 'ESTADO' ? { activo: false } : { dia_semana: 3, hora_fin: '12:00:00' }), ...(accion === 'CREAR' ? {} : { id_horario: h }) });
});
it.each(['0', '9223372036854775808', '1/estado'])('rechaza ID %s antes de HTTP', async value => {
  await expect(listarHorarios({ id: value })).rejects.toThrow(); await expect(actualizarHorario(id, value, {})).rejects.toThrow();
  await expect(consultarImpactoHorario(id, { accion: 'ESTADO', id_horario: value })).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it('sin reintento después de fallo CSRF', async () => {
  api.defaults.adapter = vi.fn(async () => { throw Error('Sin conexión'); }); await expect(crearHorario(id, {})).rejects.toThrow(); expect(api.defaults.adapter).toHaveBeenCalledTimes(1);
});
