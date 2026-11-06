// Validación exclusivamente visual. No comprueba identidad, cuentas ni aceptación financiera.
export function validarFormularioDemo(proveedor, datos) {
  const errores = {};
  const obligatorio = campo => {
    if (!datos[campo]?.trim()) errores[campo] = 'Completa este campo ficticio obligatorio.';
  };
  const campos = proveedor === 'stripe' ? ['titular', 'correo', 'tarjeta', 'mes', 'anio', 'seguridad'] : ['correo', 'clave', 'medio'];
  campos.forEach(obligatorio);
  if (datos.correo?.trim() && !/^[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@example\.test$/i.test(datos.correo.trim())) errores.correo = 'Usa un correo ficticio como comprador@example.test.';
  if (proveedor === 'stripe') {
    if (datos.titular?.trim() && (datos.titular.trim().length < 3 || datos.titular.trim().length > 80)) errores.titular = 'Escribe entre 3 y 80 caracteres ficticios.';
    if (datos.tarjeta?.trim() && !/^0000 ?0000 ?0000 ?0000$/.test(datos.tarjeta.trim())) errores.tarjeta = 'Usa únicamente el número ficticio 0000 0000 0000 0000; no es una tarjeta válida.';
    if (datos.mes?.trim() && !/^(0?[1-9]|1[0-2])$/.test(datos.mes)) errores.mes = 'Indica un mes entre 01 y 12.';
    if (datos.anio?.trim() && !/^20\d{2}$/.test(datos.anio)) errores.anio = 'Indica un año de cuatro dígitos entre 2000 y 2099.';
    // Referencia fija y visible: el ejemplo no depende del reloj real.
    if (!errores.anio && !errores.mes && Number(datos.anio) < 2030) errores.anio = 'El vencimiento debe ser posterior o igual a enero de 2030, fecha ficticia del ejemplo.';
    if (datos.seguridad?.trim() && !/^\d{3}$/.test(datos.seguridad)) errores.seguridad = 'Indica tres dígitos ficticios, por ejemplo 000.';
  } else {
    if (datos.clave?.trim() && !/^DEMO-[A-Z0-9-]{3,35}$/.test(datos.clave)) errores.clave = 'Usa una clave ficticia con prefijo DEMO-, por ejemplo DEMO-NO-REAL.';
    if (datos.medio?.trim() && !['saldo-demo', 'tarjeta-demo'].includes(datos.medio)) errores.medio = 'Selecciona un medio ficticio del ejemplo.';
  }
  return errores;
}
