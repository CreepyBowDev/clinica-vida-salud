import { afterEach, describe, expect, it } from 'vitest';
import { capturarEnlaceRecuperacion } from './recoveryLink';

afterEach(() => window.history.replaceState(null, '', '/'));

describe('token de recuperación', () => {
  it('captura el fragmento y elimina el secreto del historial antes del router', () => {
    const token = 'a'.repeat(64);
    window.history.replaceState({ posicion: 1 }, '', `/recuperar-clave#token=${token}`);
    expect(capturarEnlaceRecuperacion()).toEqual({ token });
    expect(window.location.href).not.toContain(token);
    expect(window.location.hash).toBe('');
    expect(window.history.state).toEqual({ posicion: 1 });
    expect(capturarEnlaceRecuperacion()).toEqual({ token: null });
  });

  it.each(['#token=incorrecto', '?token=' + 'a'.repeat(64), '#token=' + 'A'.repeat(64), '#token=' + 'a'.repeat(64) + '&token=' + 'b'.repeat(64), ''])('no acepta enlaces ausentes o malformados: %s', suffix => {
    window.history.replaceState(null, '', '/recuperar-clave' + suffix);
    expect(capturarEnlaceRecuperacion()).toEqual({ token: null });
    expect(window.location.hash + window.location.search).toBe('');
  });

  it('no consume una invitación de otro flujo', () => {
    window.history.replaceState(null, '', '/activar-personal#token=' + 'a'.repeat(64));
    expect(capturarEnlaceRecuperacion()).toEqual({ token: null });
    expect(window.location.hash).toContain('token=');
  });

  it('captura invitaciones solo en su ruta y retira el fragmento', () => {
    const token = 'b'.repeat(64);
    window.history.replaceState(null, '', '/activar-personal#token=' + token);
    expect(capturarEnlaceRecuperacion('/activar-personal')).toEqual({ token });
    expect(window.location.hash).toBe('');
    expect(capturarEnlaceRecuperacion('/activar-personal')).toEqual({ token: null });
  });
});
