import { afterEach, expect, it, vi } from 'vitest';
import { describirErrorClinico } from '../../utils/clinicaError';

afterEach(() => vi.useRealTimers());
it.each([['5', 5], ['1.2', 2], ['0', 0], ['-1', 0], ['invalido', 0], ['Infinity', 0]])('Retry-After %s se representa sin reintentos implícitos', (header, esperado) => {
  expect(describirErrorClinico({ response: { status: 429, headers: { 'retry-after': header } } }).retryAfter).toBe(esperado);
});
it('Retry-After de fecha HTTP respeta la espera restante', () => {
  vi.useFakeTimers(); vi.setSystemTime(new Date('2026-10-29T12:00:00Z'));
  expect(describirErrorClinico({ response: { status: 429, headers: { 'retry-after': 'Thu, 29 Oct 2026 12:00:10 GMT' } } }).retryAfter).toBe(10);
  expect(describirErrorClinico({ response: { status: 429, headers: { 'retry-after': 'Thu, 29 Oct 2026 11:59:59 GMT' } } }).retryAfter).toBe(0);
});
it('cabecera de espera no altera otro HTTP ni muestra mensajes técnicos', () => {
  const error = { response: { status: 500, headers: { 'retry-after': '999' }, data: { error: { message: 'SQL-PRIVADO' } } } };
  const r = describirErrorClinico(error); expect(r.retryAfter).toBe(0); expect(r.message).not.toContain('SQL-PRIVADO'); expect(r.fields).toEqual({});
});
