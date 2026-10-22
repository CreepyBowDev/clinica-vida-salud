import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { api, csrfURL } from '../../../../api/api';
import { consultarAsignacion, consultarImpactoAsignacion, confirmarAsignacion } from '../../services/asignacionConsultorio.service';

const original = api.defaults.adapter;
const id = '9223372036854775807';
const version = '2026-10-22T12:00:00.123456Z';
let calls;
beforeEach(() => { calls = []; api.defaults.adapter = vi.fn(async config => { calls.push(config); return { config, status: 200, headers: {}, data: { data: { id_medico: id }, meta: { total: 0 } } }; }); });
afterEach(() => { api.defaults.adapter = original; });
it('lee asignación con ID textual, sesión y cancelación', async () => {
  const signal = new AbortController().signal;
  expect(await consultarAsignacion(id, signal)).toEqual({ id_medico: id });
  expect(calls[0]).toMatchObject({ url: `/medicos/${id}/consultorio`, signal, sessionBound: true });
});
it('pagina impacto mediante POST del cuerpo cerrado tras CSRF, sin convertir enlaces en GET', async () => {
  const signal = new AbortController().signal;
  const result = await consultarImpactoAsignacion(id, { id_consultorio: id, actualizado_en_original: version, page: 3, per_page: 10, estado: 'Atendida' }, signal);
  expect(calls[0].url).toBe(csrfURL); expect(calls[1]).toMatchObject({ method: 'post', url: `/medicos/${id}/consultorio/impacto`, signal, sessionBound: true });
  expect(JSON.parse(calls[1].data)).toEqual({ id_consultorio: id, actualizado_en_original: version, page: 3, per_page: 10 }); expect(result.meta.total).toBe(0);
});
it('confirma huella, versión completa y consentimiento booleano sin campos adicionales', async () => {
  await confirmarAsignacion(id, { id_consultorio: id, actualizado_en_original: version, huella_impacto: 'a'.repeat(64), confirmar_traslados: false, citas: ['1'], rol: 'Administrador' });
  expect(calls[1]).toMatchObject({ method: 'put', url: `/medicos/${id}/consultorio`, sessionBound: true });
  expect(JSON.parse(calls[1].data)).toEqual({ id_consultorio: id, actualizado_en_original: version, huella_impacto: 'a'.repeat(64), confirmar_traslados: false });
});
it.each(['0', '9223372036854775808', '1/estado'])('rechaza ID inválido %s antes de HTTP', async value => {
  await expect(consultarAsignacion(value)).rejects.toThrow();
  await expect(consultarImpactoAsignacion(id, { id_consultorio: value })).rejects.toThrow();
  await expect(confirmarAsignacion(value, {})).rejects.toThrow(); expect(calls).toHaveLength(0);
});
it('no reintenta confirmación cuando el transporte falla', async () => {
  api.defaults.adapter = vi.fn(async config => { calls.push(config); if (config.method === 'put') throw new Error('Sin conexión'); return { config, status: 204, headers: {}, data: '' }; });
  await expect(confirmarAsignacion(id, { id_consultorio: id })).rejects.toThrow(); expect(calls).toHaveLength(2);
});
