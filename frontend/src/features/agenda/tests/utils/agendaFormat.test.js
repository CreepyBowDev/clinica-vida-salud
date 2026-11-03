import { expect, it } from 'vitest';
import { esFechaCivil, esInstanteCita, formatearDiaCita, formatearHoraCita, formatearFechaCivil, formatearInstanteCita } from '../../utils/agendaFormat';

it.each(['0001-01-01', '2000-02-29', '9999-12-31'])('fecha civil válida %s sin horizonte de reserva', value => expect(esFechaCivil(value)).toBe(true));
it.each(['0000-01-01', '1900-02-29', '2026-02-30', '2026-13-01', '2026-00-01', '2026-01-00', null])('rechaza fecha %s', value => expect(esFechaCivil(value)).toBe(false));
it('fecha de nacimiento se presenta literalmente sin cambio por zona', () => expect(formatearFechaCivil('1990-01-01')).toBe('01/01/1990'));
it('horario usa sede incluso si UTC pertenece al día siguiente y conserva valor original', () => {
  const value = '2026-11-01T02:30:00.123456Z';
  expect(formatearInstanteCita(value)).toContain('31/10/2026'); expect(formatearInstanteCita(value)).toContain('22:30');
  expect(value).toBe('2026-11-01T02:30:00.123456Z');
});
it('instantes inválidos y fechas ausentes tienen fallback seguro', () => {
  expect(esInstanteCita('2026-11-01T24:00:00.000000Z')).toBe(false);
  expect(formatearInstanteCita('NO')).toBe('No disponible'); expect(formatearFechaCivil(null)).toBe('No registrado');
});

it('hora y día del piloto usan la sede, no el día UTC ni la zona del dispositivo', () => {
  expect(formatearHoraCita('2026-11-01T02:30:00.123456Z')).toBe('22:30');
  expect(formatearDiaCita('2026-11-01T02:30:00.123456Z')).toBe('31/10/2026');
  expect(formatearHoraCita('2026-11-01T04:05:00.000000Z')).toBe('00:05');
  expect(formatearDiaCita('2026-11-01T04:05:00.000000Z')).toBe('01/11/2026');
});
it.each([null, 'NO', '2026-02-30T12:00:00.000000Z'])('hora y día inválidos mantienen fallback seguro: %s', value => {
  expect(formatearHoraCita(value)).toBe('No disponible'); expect(formatearDiaCita(value)).toBe('No disponible');
});
