import { useRef, useState } from 'react';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Button from '../../../components/Button';
import { validarFormularioDemo } from '../utils/demostracionPagoValidacion';

export default function FormularioPagoDemo({ proveedor, datos, onChange, onRevisar }) {
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
    <p>Todos los campos son obligatorios y están precargados para la exposición. Puedes borrarlos y corregir los errores. Solo se comprueba formato local; no identidad, cuentas, solvencia ni aceptación externa.</p>
    {proveedor === 'stripe' ? <>
      <p>En la integración auténtica estos datos se introducen en Stripe Checkout alojado, no en un formulario de la clínica. Este ejemplo nunca se envía ni se reutiliza para pagar.</p>
      <FormField label="Nombre del titular ficticio" maxLength={80} {...field('titular')} />
      <FormField label="Correo ficticio" type="text" inputMode="email" maxLength={100} hint="Usa únicamente el dominio ficticio @example.test." {...field('correo')} />
      <FormField label="Número de tarjeta ficticio" type="text" inputMode="numeric" maxLength={19} hint="0000 0000 0000 0000: número deliberadamente ficticio, no tarjeta válida ni tarjeta oficial de prueba." {...field('tarjeta')} />
      <p>Fecha ficticia de referencia: enero de 2030. No se comprueba vigencia financiera real.</p>
      <FormField label="Mes de vencimiento ficticio" inputMode="numeric" maxLength={2} {...field('mes')} />
      <FormField label="Año de vencimiento ficticio" inputMode="numeric" maxLength={4} {...field('anio')} />
      <FormField label="Código de seguridad ficticio" type="text" inputMode="numeric" maxLength={3} hint="Ejemplo ficticio: 000. No introduzcas un código real." {...field('seguridad')} />
    </> : <>
      <p>En una integración auténtica la autenticación ocurre dentro de PayPal; la clínica no recibe las credenciales. Aquí no se verifica ninguna cuenta ni contraseña.</p>
      <FormField label="Correo de comprador ficticio" type="text" inputMode="email" maxLength={100} hint="Usa únicamente el dominio ficticio @example.test." {...field('correo')} />
      <FormField label="Clave exclusivamente de demostración" type="text" maxLength={40} spellCheck={false} hint="Texto demo visible, no contraseña PayPal: DEMO-NO-REAL." {...field('clave')} />
      <SelectField label="Medio de pago ficticio" {...field('medio')}><option value="">Selecciona un medio ficticio</option><option value="saldo-demo">Saldo ficticio del ejemplo</option><option value="tarjeta-demo">Tarjeta ficticia del ejemplo</option></SelectField>
    </>}
    {Object.values(errores).some(Boolean) && <p role="alert">Revisa los campos indicados. No se puede continuar con errores; la validación local no verifica un pago.</p>}
    <Button type="submit">Revisar inicio simulado</Button>
  </form>;
}
