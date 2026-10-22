import { expect, it } from 'vitest';
import { diaSemana, horaLocal, fechaSede } from '../../utils/horarioFormat';
it('días ISO y horas conservan segundos sin imponer pasos', () => {
  expect(diaSemana(1)).toBe('Lunes'); expect(diaSemana(7)).toBe('Domingo'); expect(diaSemana(0)).toBe('No disponible');
  expect(horaLocal('08:00:01')).toBe('08:00:01'); expect(horaLocal('24:00:00')).toBe('No disponible'); expect(horaLocal('08:00')).toBe('No disponible');
});
it('fechas UTC se muestran en sede y no normaliza fechas imposibles', () => {
  expect(fechaSede('2026-11-01T12:00:00.123456Z')).toContain('08:00:00');
  expect(fechaSede('2026-02-30T12:00:00.000000Z')).toBe('No disponible'); expect(fechaSede('2026-11-01')).toBe('No disponible');
});
