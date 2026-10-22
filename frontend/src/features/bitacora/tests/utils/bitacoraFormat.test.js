import { describe, expect, it } from 'vitest';
import { etiquetaActor, etiquetaResultado, formatearFechaEvento } from '../../utils/bitacoraFormat';

describe('presentación de valores de Bitácora', () => {
  it('presenta el día y hora de La Paz aunque UTC ya esté en el siguiente día', () => {
    expect(formatearFechaEvento('2026-10-22T02:30:01.123456Z')).toBe('21/10/2026, 22:30:01');
  });
  it.each([null, undefined, '', '2026-10-21', '2026-10-21T14:00:00', '2026-99-99T14:00:00.000000Z'])('no interpreta fechas sin zona o inválidas: %s', valor => {
    expect(formatearFechaEvento(valor)).toBe('No disponible');
  });
  it('distingue los tres orígenes sin inventar un responsable', () => {
    expect(['USUARIO', 'SISTEMA', 'ANONIMO'].map(etiquetaActor)).toEqual(['Usuario', 'Sistema', 'Anónimo']);
  });
  it.each(['2026-02-30T12:00:00.000000Z', '2025-02-29T12:00:00.000000Z', '2026-04-31T12:00:00.000000Z', '2026-10-21T24:00:00.000000Z'])('no normaliza silenciosamente una fecha imposible: %s', valor => {
    expect(formatearFechaEvento(valor)).toBe('No disponible');
  });
  it('admite el día bisiesto y conserva horario de sede al truncar solo para presentación', () => {
    expect(formatearFechaEvento('2024-02-29T14:00:00.999999Z')).toBe('29/02/2024, 10:00:00');
  });
  it('traduce los cuatro resultados y no imprime valores desconocidos', () => {
    expect(['EXITO', 'FALLO', 'DENEGADO', 'ERROR'].map(etiquetaResultado)).toEqual(['Éxito', 'Fallo', 'Denegado', 'Error']);
    for (const valor of [null, '__proto__', 'constructor', 'valor desconocido']) {
      expect(etiquetaActor(valor)).toBe('No disponible'); expect(etiquetaResultado(valor)).toBe('No disponible');
    }
  });
});
