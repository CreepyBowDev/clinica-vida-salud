// Ejemplo visual estático, sin identificadores de recursos ni cuentas reales.
export const DEMOSTRACION_PAGO = Object.freeze({
  codigo_cita: 'DEMO-CITA-001',
  precio: Object.freeze({ monto_bob: '70.00', tasa_bob_por_usd: '7.00', monto_usd: '10.00' }),
  // Número deliberadamente ficticio, no tarjeta oficial ni medio aceptado por un proveedor.
  stripe: Object.freeze({ titular: 'Persona Ficticia Demo', correo: 'comprador@example.test', tarjeta: '0000 0000 0000 0000', mes: '12', anio: '2035', seguridad: '000' }),
  paypal: Object.freeze({ correo: 'comprador@example.test', clave: 'DEMO-NO-REAL', medio: 'saldo-demo' }),
});
