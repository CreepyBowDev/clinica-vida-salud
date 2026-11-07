import { useRef, useState } from 'react';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Button from '../../../components/Button';
import { validarFormularioDemo } from '../utils/demostracionPagoValidacion';

export default function FormularioPagoDemo({ proveedor, datos, onChange, onRevisar, persistente = false }) {
  const [errores, setErrores] = useState({});
  const form = useRef(null);
  const field = nombre => ({
    name: nombre, value: datos[nombre], required: true, autoComplete: 'off', error: errores[nombre],
    onBlur: () => setErrores(actual => ({ ...actual, [nombre]: validarFormularioDemo(proveedor, datos)[nombre] })),
    onChange: e => {
      const siguientes = { ...datos, [nombre]: e.target.value };
      onChange(siguientes);
      if (errores[nombre]) setErrores(actual => ({ ...actual, [nombre]: validarFormularioDemo(proveedor, siguientes)[nombre] }));
    },
  });
  function submit(e) {
    e.preventDefault();
    const siguientes = validarFormularioDemo(proveedor, datos);
    setErrores(siguientes);
    const primero = Object.keys(siguientes)[0];
    if (primero) { form.current.elements.namedItem(primero)?.focus(); return; }
    onRevisar();
  }
  return <form ref={form} className="payment-demo-form" aria-label={`Formulario ${proveedor === 'stripe' ? 'Stripe' : 'PayPal'} ficticio`} noValidate autoComplete="off" onSubmit={submit}>
    <h3>Formulario {proveedor === 'stripe' ? 'Stripe' : 'PayPal'} ficticio</h3>
    <p>Todos los campos son obligatorios y están precargados para la exposición. Puedes editarlos. Solo se comprueba formato local; no identidad, cuentas, solvencia ni aceptación externa.</p>
    {proveedor === 'stripe' ? <>
      <p>En la integración auténtica estos datos se introducen en Stripe Checkout alojado, no en un formulario de la clínica. {persistente ? 'Este formulario es local: sus campos no se envían ni se guardan; solo se registra la operación académica.' : 'Este ejemplo nunca se envía ni se reutiliza para pagar.'}</p>
      <fieldset className="payment-form-group"><legend>Persona ficticia</legend>
      <FormField label={persistente ? 'Titular' : 'Nombre del titular ficticio'} maxLength={80} {...field('titular')} />
      <FormField label={persistente ? 'Correo' : 'Correo ficticio'} type="text" inputMode="email" maxLength={100} hint="Usa únicamente el dominio ficticio @example.test." {...field('correo')} />
      </fieldset>
      <fieldset className="payment-form-group"><legend>Tarjeta del ejemplo</legend>
      <FormField label={persistente ? 'Tarjeta' : 'Número de tarjeta ficticio'} type="text" inputMode="numeric" maxLength={19} hint="0000 0000 0000 0000: número deliberadamente ficticio, no tarjeta válida ni tarjeta oficial de prueba." {...field('tarjeta')} />
      <p>Fecha ficticia de referencia: enero de 2030. No se comprueba vigencia financiera real.</p>
      <div className="payment-form-row">
      <FormField label={persistente ? 'Mes de vencimiento' : 'Mes de vencimiento ficticio'} inputMode="numeric" maxLength={2} {...field('mes')} />
      <FormField label={persistente ? 'Año de vencimiento' : 'Año de vencimiento ficticio'} inputMode="numeric" maxLength={4} {...field('anio')} />
      </div>
      <FormField label={persistente ? 'Código de seguridad' : 'Código de seguridad ficticio'} type="text" inputMode="numeric" maxLength={3} hint="Ejemplo ficticio: 000. No introduzcas un código real." {...field('seguridad')} />
      </fieldset>
    </> : <>
      <p>En una integración auténtica la autenticación ocurre dentro de PayPal; la clínica no recibe las credenciales. Aquí no se verifica ninguna cuenta ni contraseña.</p>
      <fieldset className="payment-form-group"><legend>Comprador del ejemplo, sin inicio de sesión</legend>
      <FormField label={persistente ? 'Correo' : 'Correo de comprador ficticio'} type="text" inputMode="email" maxLength={100} hint="Usa únicamente el dominio ficticio @example.test." {...field('correo')} />
      <FormField label={persistente ? 'Clave del ejemplo' : 'Clave exclusivamente de demostración'} type="text" maxLength={40} spellCheck={false} hint="Texto demo visible, no contraseña PayPal: DEMO-NO-REAL." {...field('clave')} />
      <SelectField label={persistente ? 'Medio de pago' : 'Medio de pago ficticio'} {...field('medio')}><option value="">Selecciona un medio ficticio</option><option value="saldo-demo">Saldo ficticio del ejemplo</option><option value="tarjeta-demo">Tarjeta ficticia del ejemplo</option></SelectField>
      </fieldset>
    </>}
    {Object.values(errores).some(Boolean) && <p role="alert">Revisa los campos indicados. No se puede continuar con errores; la validación local no verifica un pago.</p>}
    <div className="payment-form-submit"><p>Revisar todavía no registra ninguna operación.</p><Button type="submit">Revisar inicio simulado</Button></div>
  </form>;
}
