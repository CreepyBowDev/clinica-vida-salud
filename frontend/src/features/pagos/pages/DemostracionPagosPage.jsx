import { useEffect, useRef, useState } from 'react';
import PageHeading from '../../../components/PageHeading';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import SelectField from '../../../components/SelectField';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import PrecioPago from '../components/PrecioPago';
import FormularioPagoDemo from '../components/FormularioPagoDemo';
import { DEMOSTRACION_PAGO } from '../utils/demostracionPagoFixture';
import '../../../styles/catalogs.css';
import '../../../styles/workflows.css';
import '../styles/pagos.css';

const inicial = { paso: 'seleccion', proveedor: 'stripe', escenario: 'completado' };
const proveedores = { stripe: 'Stripe', paypal: 'PayPal' };
const titulos = {
  seleccion: 'Seleccionar recorrido ficticio', revision: 'Revisar inicio simulado',
  iniciado: 'Inicio simulado preparado', proveedor: 'Salida al proveedor simulada',
  aprobacion: 'Aprobación PayPal simulada', retorno: 'Retorno simulado al portal',
  aprobado: 'Resultado ficticio: aprobación sin captura', revisionCaptura: 'Revisar captura simulada',
  captura: 'Captura humana simulada solicitada', completado: 'Resultado ficticio: pago completado simulado',
  pendiente: 'Resultado ficticio: verificación pendiente',
};

export default function DemostracionPagosPage() {
  const [demo, setDemo] = useState(inicial);
  const [formularios, setFormularios] = useState(() => ({ stripe: { ...DEMOSTRACION_PAGO.stripe }, paypal: { ...DEMOSTRACION_PAGO.paypal } }));
  const heading = useRef(null), first = useRef(true);
  const { paso, proveedor, escenario } = demo;
  // Solo accesibilidad: ninguna consulta, temporizador o efecto financiero.
  useEffect(() => {
    if (first.current) { first.current = false; return; }
    heading.current?.focus({ preventScroll: true });
  }, [paso]);
  const avanzar = (desde, hacia) => setDemo(actual => actual.paso === desde ? { ...actual, paso: hacia } : actual);
  const consultar = () => avanzar('retorno', escenario === 'pendiente' ? 'pendiente' : proveedor === 'paypal' ? 'aprobado' : 'completado');
  return <section className="workspace-page catalog-page payments-page payment-demo">
    <PageHeading title="Demostración de pagos" description="Recorrido visual ficticio del Paciente, separado de Consultar pagos y de sus datos reales." />
    <Alert variant="warning"><p>Demostración académica con datos ficticios. No realiza cobros ni modifica citas o pagos</p></Alert>
    <p><strong>No introduzcas tarjetas, contraseñas ni credenciales reales</strong>.</p>
    <p>El Administrador puede explorar este ejemplo sin suplantar al Paciente ni pagar en su nombre. No se consulta ninguna ficha real.</p>
    <section className="catalog-panel" aria-label="Obligación ficticia">
      <h2>Obligación ficticia · {DEMOSTRACION_PAGO.codigo_cita}</h2>
      <p>Ejemplo estático: pendiente de pago simulado. La cita de ejemplo permanece Programada durante todo el recorrido.</p>
      <PrecioPago precio={DEMOSTRACION_PAGO.precio} />
      <p>BOB 70,00 / 7 = USD 10,00. Tasa académica 7:1, no cotización oficial. Estos importes no corresponden a una obligación real.</p>
    </section>
    <section className="catalog-panel payment-demo-step" aria-label="Recorrido simulado">
      <h2 ref={heading} tabIndex={-1}>{titulos[paso]}</h2>
      <p>Proveedor del ejemplo: <strong>{proveedores[proveedor]}</strong>. Escenario ficticio: {escenario === 'pendiente' ? 'verificación pendiente' : 'completado tras consulta explícita'}.</p>
      {paso === 'seleccion' && <>
        <SelectField label="Proveedor simulado" value={proveedor} onChange={e => setDemo({ ...inicial, proveedor: e.target.value, escenario })}>
          <option value="stripe">Stripe (simulado)</option><option value="paypal">PayPal (simulado)</option>
        </SelectField>
        <SelectField label="Escenario ficticio" value={escenario} onChange={e => setDemo({ ...inicial, proveedor, escenario: e.target.value })}>
          <option value="completado">Completado simulado</option><option value="pendiente">Verificación pendiente simulada</option>
        </SelectField>
        <FormularioPagoDemo key={`${proveedor}:${demo.reinicio ?? 0}`} proveedor={proveedor} datos={formularios[proveedor]}
          onChange={datos => setFormularios(actual => ({ ...actual, [proveedor]: datos }))} onRevisar={() => avanzar('seleccion', 'revision')} />
      </>}
      {paso === 'revision' && <ConfirmationPanel title="Confirmar importe ficticio">
        <p>Revisa Bs 70,00, tasa 7:1 y USD 10,00 con {proveedores[proveedor]}. Iniciar solo cambia esta pantalla; no envía una solicitud de pago.</p>
        <p>Formulario ficticio revisado localmente. No acredita identidad, fondos ni aceptación financiera. Puedes volver a editar antes de iniciar.</p>
        <ActionGroup><Button onClick={() => avanzar('revision', 'iniciado')}>Iniciar pago simulado</Button>
          <Button variant="quiet" onClick={() => avanzar('revision', 'seleccion')}>Volver a selección ficticia</Button></ActionGroup>
      </ConfirmationPanel>}
      {paso === 'iniciado' && <>
        <p>Inicio simulado para USD 10,00. No se creó ningún intento, sesión Checkout u orden PayPal.</p>
        <Button onClick={() => avanzar('iniciado', 'proveedor')}>Representar salida al proveedor (simulada)</Button>
      </>}
      {paso === 'proveedor' && <>
        <p>Esta pantalla representa la salida a {proveedores[proveedor]}. Sigues en la clínica: no se abre un enlace, SDK ni formulario real del proveedor. Los campos anteriores solo pertenecen al ejemplo local.</p>
        {proveedor === 'paypal' ? <>
          <p>La aprobación ficticia es distinta de la captura y no representa un cobro.</p>
          <Button onClick={() => avanzar('proveedor', 'aprobacion')}>Simular aprobación PayPal</Button>
        </> : <Button onClick={() => avanzar('proveedor', 'retorno')}>Simular retorno al portal</Button>}
      </>}
      {paso === 'aprobacion' && <>
        <p>Aprobación ficticia concedida. Sin captura ni pago. Volver al portal no cambia eso.</p>
        <Button onClick={() => avanzar('aprobacion', 'retorno')}>Simular retorno al portal</Button>
      </>}
      {paso === 'retorno' && <>
        <p>El retorno simulado no acredita éxito ni captura. Todavía no se ha consultado el resultado ficticio.</p>
        <Button onClick={consultar}>Consultar resultado ficticio</Button>
      </>}
      {paso === 'aprobado' && <>
        <p>El ejemplo muestra aprobación PayPal, no pago. No hubo captura automática. Revisa el importe antes de representar la captura humana.</p>
        <Button onClick={() => avanzar('aprobado', 'revisionCaptura')}>Revisar captura PayPal simulada</Button>
      </>}
      {paso === 'revisionCaptura' && <ConfirmationPanel title="Confirmar captura ficticia PayPal">
        <p>Revisión explícita: Bs 70,00, tasa 7:1, USD 10,00. Esta acción humana solo representa una captura, sin enviarla a PayPal.</p>
        <ActionGroup><Button onClick={() => avanzar('revisionCaptura', 'captura')}>Simular captura PayPal</Button>
          <Button variant="quiet" onClick={() => avanzar('revisionCaptura', 'aprobado')}>Volver sin simular captura</Button></ActionGroup>
      </ConfirmationPanel>}
      {paso === 'captura' && <>
        <p>Representación local de una solicitud humana de captura. Esto aún no muestra un resultado ni acredita dinero recibido.</p>
        <Button onClick={() => avanzar('captura', 'completado')}>Consultar resultado ficticio de captura</Button>
      </>}
      {paso === 'completado' && <Alert variant="info"><p>Pago completado simulado por USD 10,00. Resultado de una fixture, no verificado por {proveedores[proveedor]} ni registrado en el sistema. No es comprobante ni acredita un cobro.</p></Alert>}
      {paso === 'pendiente' && <Alert variant="warning"><p>Verificación pendiente simulada. No equivale a éxito ni a fallo. No hay consultas automáticas ni polling; el ejemplo permanecerá pendiente hasta reiniciar. No habilita otro pago ni una captura.</p></Alert>}
    </section>
    <ActionGroup><Button variant="secondary" onClick={() => { setDemo(actual => ({ ...inicial, reinicio: (actual.reinicio ?? 0) + 1 })); setFormularios({ stripe: { ...DEMOSTRACION_PAGO.stripe }, paypal: { ...DEMOSTRACION_PAGO.paypal } }); heading.current?.focus({ preventScroll: true }); }}>Reiniciar demostración</Button></ActionGroup>
    <p className="catalog-note">Reiniciar, recargar o salir descarta únicamente el recorrido en memoria. Sin almacenamiento del navegador, llamadas financieras ni cambios de configuración. Ningún resultado de esta página procede de Stripe o PayPal.</p>
  </section>;
}
