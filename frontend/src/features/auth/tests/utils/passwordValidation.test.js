import { describe, expect, it } from 'vitest';
import { evaluarRequisitosClave, validarNuevaClave } from '../../utils/passwordValidation';

describe('política compartida de contraseñas nuevas', () => {
  it.each([
    ['frase ficticia 123!', 'una mayúscula'],
    ['FRASE FICTICIA 123!', 'una minúscula'],
    ['Frase ficticia larga!', 'un número'],
    ['  Frase ficticia 123  ', 'un carácter especial'],
    ['FraseFicticia123\u00a0', 'un carácter especial'],
    ['Frase ficticia Ⅳ!', 'un número'],
  ])('identifica lo que falta sin devolver la clave: %s', (password, missing) => {
    const fields = validarNuevaClave(password, password);
    expect(fields.password).toContain(missing);
    expect(fields.password).not.toContain(password);
    expect(fields.password_confirmation).toBeUndefined();
  });

  it.each(['Aa1!abcdefgh', 'Árbol ficticio ١€', 'Frase ficticia 123🙂', '  Frase ficticia 123!  '])('admite Unicode y espacios sin recortar: %s', password => {
    expect(validarNuevaClave(password, password)).toEqual({});
    expect(evaluarRequisitosClave(password).every(requirement => requirement.met)).toBe(true);
  });

  it('cuenta caracteres Unicode, no unidades UTF-16', () => {
    expect(validarNuevaClave('Aa1!🙂🙂🙂🙂🙂🙂🙂', 'Aa1!🙂🙂🙂🙂🙂🙂🙂').password).toContain('al menos 12');
    expect(validarNuevaClave('Aa1!🙂🙂🙂🙂🙂🙂🙂🙂', 'Aa1!🙂🙂🙂🙂🙂🙂🙂🙂')).toEqual({});
  });

  it('mantiene límite común y deja al servidor los límites de su hash', () => {
    const maximum = 'Aa1!' + 'a'.repeat(1020);
    expect(validarNuevaClave(maximum, maximum)).toEqual({});
    expect(validarNuevaClave(maximum + 'a', maximum + 'a').password).toContain('1024');
  });

  it('compara la confirmación exactamente, sin exigirle composición aparte', () => {
    expect(validarNuevaClave(' Frase ficticia 123! ', 'Frase ficticia 123!').password_confirmation).toContain('no coincide');
    expect(evaluarRequisitosClave('').every(requirement => !requirement.met)).toBe(true);
  });
});
