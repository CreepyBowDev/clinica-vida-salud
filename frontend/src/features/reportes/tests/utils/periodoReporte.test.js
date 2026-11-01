import { expect, it } from 'vitest';
import { esPeriodoReporte } from '../../utils/periodoReporte';

it.each([
  { fecha: '2026-10-31' }, { fecha: '0001-01-01' }, { fecha: '2024-02-29' },
  { desde: '2026-10-31', hasta: '2026-10-31' }, { desde: '2024-01-01', hasta: '2024-12-31' },
  { desde: '2026-01-01', hasta: '2027-01-01' }, { desde: '0001-01-01', hasta: '0002-01-01' },
])('acepta fechas civiles y hasta 366 días inclusivos: %j', params => expect(esPeriodoReporte(params)).toBe(true));
it.each([
  {}, { fecha: '' }, { fecha: '2026-02-29' }, { fecha: '0000-01-01' }, { fecha: '2026-04-31' },
  { fecha: '2026-01-01', desde: '2026-01-01', hasta: '2026-01-02' },
  { fecha: '2026-01-01', desde: '' }, { desde: '2026-01-01' }, { hasta: '2026-01-01' },
  { desde: '', hasta: '' }, { desde: '2026-11-01', hasta: '2026-10-31' },
  { desde: '2024-01-01', hasta: '2025-01-01' }, { desde: '2026-01-01', hasta: '2027-01-02' },
  { desde: '2026-02-30', hasta: '2026-03-01' }, { desde: ['2026-01-01'], hasta: '2026-01-02' },
])('rechaza mezcla, incompletos, imposibles, inversión o exceso: %j', params => expect(esPeriodoReporte(params)).toBe(false));
