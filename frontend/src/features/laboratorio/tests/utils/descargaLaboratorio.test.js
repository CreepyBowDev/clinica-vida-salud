import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { iniciarDescarga } from '../../utils/descargaLaboratorio';

beforeEach(() => { vi.useFakeTimers(); URL.createObjectURL = vi.fn(() => 'blob:ficticio'); URL.revokeObjectURL = vi.fn(); });
afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers(); });
it('inicia download sin visor y libera URL después, no antes del click', () => {
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
    expect(this.download).toBe('resultado-81.pdf'); expect(this.target).toBe(''); expect(this.isConnected).toBe(true); expect(URL.revokeObjectURL).not.toHaveBeenCalled();
  });
  const release = iniciarDescarga({ blob: new Blob(['ficticio']), nombre: 'resultado-81.pdf' });
  expect(click).toHaveBeenCalledTimes(1); expect(document.querySelector('a[download]')).toBeNull();
  vi.advanceTimersByTime(60000); expect(URL.revokeObjectURL).toHaveBeenCalledExactlyOnceWith('blob:ficticio'); release(); expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1);
});
it('retiro del contexto libera inmediatamente y cancela temporizador', () => {
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  const release = iniciarDescarga({ blob: new Blob(), nombre: 'resultado-81.pdf' }); release();
  expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1); expect(vi.getTimerCount()).toBe(0);
});
it('fallo al iniciar no deja enlace o URL', () => {
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => { throw Error('fallo'); });
  expect(() => iniciarDescarga({ blob: new Blob(), nombre: 'resultado-81.pdf' })).toThrow();
  expect(URL.revokeObjectURL).toHaveBeenCalledTimes(1); expect(document.querySelector('a[download]')).toBeNull();
});
