import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import PageHeading from '../../../components/PageHeading';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import SelectField from '../../../components/SelectField';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import ActionGroup from '../../../components/ActionGroup';
import { escucharCambioSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { consultarPagos, operarSimulacion } from '../services/pago.service';
import { usePagoQuery } from '../hooks/usePagoQuery';
import { errorPago } from '../utils/pagoError';
import { esUuid, situacionObligacion } from '../utils/pagoFormat';
import { DEMOSTRACION_PAGO } from '../utils/demostracionPagoFixture';
import FormularioPagoDemo from '../components/FormularioPagoDemo';
import PrecioPago from '../components/PrecioPago';
import '../../../styles/catalogs.css';
import '../../../styles/workflows.css';
import '../styles/pagos.css';

export default function PagarPage() {
  const { id } = useParams();
  return <Recorrido key={id} id={id} />;
}
function Recorrido({ id }) {
  const query = usePagoQuery(consultarPagos, { tipo: 'detalle', id, rol: ROLES.PACIENTE }, esUuid(id));
  const [modalidad, setModalidad] = useState('stripe'), [escenario, setEscenario] = useState('completado');
  const [formularios, setFormularios] = useState(() => ({ stripe: { ...DEMOSTRACION_PAGO.stripe }, paypal: { ...DEMOSTRACION_PAGO.paypal } }));
  const [paso, setPaso] = useState('seleccion'), [busy, setBusy] = useState(false), [failure, setFailure] = useState(null), [incierta, setIncierta] = useState(false);
  const pending = useRef(null), locked = useRef(false), generation = useRef(0), heading = useRef(null);
  useEffect(() => {
    const clear = () => { generation.current++; locked.current = false; pending.current = null; setBusy(false); setIncierta(false); setFormularios({ stripe: { ...DEMOSTRACION_PAGO.stripe }, paypal: { ...DEMOSTRACION_PAGO.paypal } }); };
    const off = escucharCambioSesion(clear);
    return () => { generation.current++; off(); };
  }, []);
  useEffect(() => { heading.current?.focus({ preventScroll: true }); }, [paso]);
  async function enviar(tipo, recuperar = false) {
    if (locked.current || (pending.current && !recuperar)) return;
    const input = recuperar ? pending.current : { tipo, id: tipo === 'iniciar' ? id : query.data?.intento_actual?.id,
      clave: crypto.randomUUID(), modalidad, escenario, revision: query.data?.simulacion?.revision };
    if (!input) return;
    pending.current = input; locked.current = true; const seq = ++generation.current;
    setBusy(true); setFailure(null);
    try {
      await operarSimulacion(input);
      if (seq !== generation.current) return;
      pending.current = null; setIncierta(false);
      setPaso(input.tipo === 'iniciar' ? 'salida' : 'retorno'); query.reload();
    } catch (error) {
      if (seq !== generation.current) return;
      const f = errorPago(error), uncertain = error.pagoEnviado !== false && (!f.status || f.status >= 500);
      setFailure(f); setIncierta(uncertain);
      if (!uncertain) pending.current = null;
      query.reload();
    } finally { if (seq === generation.current) { locked.current = false; setBusy(false); } }
  }
  const o = query.data, i = o?.intento_actual?.proveedor === 'simulado' ? o.intento_actual : null;
  const enabled = o?.simulacion?.habilitada, blocked = busy || incierta;
  return <section className="workspace-page catalog-page payments-page payment-demo">
    <Link className="back-link" to={`/pagos/obligaciones/${id}`}>Volver a la obligación e historial</Link>
    <PageHeading title="Pagar" description="Recorrido académico vinculado a la obligación de tu cita." />
    <Alert variant="warning"><p>Pago académico simulado. Registra un resultado local, sin enviar dinero ni contactar con Stripe o PayPal. No introduzcas datos reales.</p></Alert>
    <p>Los formularios son representaciones locales, no Checkout ni autenticación auténticos.</p>
    {!esUuid(id) ? <Alert><p>No se encontró el recurso solicitado.</p></Alert> : query.loading ? <p role="status">Consultando obligación…</p> : query.failure ? <Alert><p>{query.failure.message}</p></Alert> : o && <>
      <section className="catalog-panel"><h2>Cita {o.cita.codigo_cita}</h2><PrecioPago precio={o.precio} />
        <p>Estado asistencial: {o.cita.estado}. Situación financiera: <strong>{situacionObligacion(o)}</strong>.</p></section>
      <h2 ref={heading} tabIndex={-1}>{paso === 'revision' ? 'Revisar importe' : paso === 'salida' ? 'Salida simulada' : paso === 'captura' ? 'Revisar captura' : paso === 'cierre' ? 'Revisar cierre' : 'Recorrido académico'}</h2>
      {!enabled && <Alert><p>El recorrido académico no está habilitado en este entorno.</p></Alert>}
      {enabled && !o.liquidada && o.exigible && !i && o.simulacion.puede_iniciar && <fieldset disabled={blocked}>
        {paso !== 'revision' ? <>
          <SelectField label="Modalidad" value={modalidad} onChange={e => { setModalidad(e.target.value); setPaso('seleccion'); }}><option value="stripe">Stripe (representación)</option><option value="paypal">PayPal (representación)</option></SelectField>
          <SelectField label="Escenario académico" value={escenario} onChange={e => setEscenario(e.target.value)}><option value="completado">Completar operación</option><option value="pendiente">Permanecer pendiente</option></SelectField>
          <FormularioPagoDemo key={modalidad} persistente proveedor={modalidad} datos={formularios[modalidad]}
            onChange={datos => setFormularios(v => ({ ...v, [modalidad]: datos }))} onRevisar={() => setPaso('revision')} />
        </> : <ConfirmationPanel title="Confirmar importe"><PrecioPago precio={o.precio} />
          <p>Revisa el importe histórico y la tasa académica 7:1 antes de iniciar con {modalidad === 'paypal' ? 'PayPal' : 'Stripe'} representativo.</p>
          <ActionGroup><Button onClick={() => enviar('iniciar')}>Iniciar pago académico</Button><Button variant="quiet" onClick={() => setPaso('seleccion')}>Volver al formulario</Button></ActionGroup>
        </ConfirmationPanel>}
      </fieldset>}
      {enabled && i && o.exigible && !o.liquidada && <fieldset disabled={blocked}>
        <p>Intento académico guardado. Modalidad {i.modalidad === 'paypal' ? 'PayPal' : 'Stripe'}. {i.aprobada ? 'Aprobación registrada, sin captura.' : 'Sin cobro aplicado.'}</p>
        {paso === 'salida' ? <><p>Sigues en la clínica. No se abre ningún proveedor.</p><Button onClick={() => setPaso('retorno')}>Volver al portal</Button></> : <>
          <p>El retorno no implica éxito. El resultado procede del backend, nunca de esta transición visual.</p>
          {i.escenario === 'pendiente' ? <Alert variant="warning"><p>Resultado pendiente. No equivale a éxito ni fallo; no hay polling y no se habilita otro pago.</p></Alert>
            : i.modalidad === 'stripe' ? <Button onClick={() => enviar('confirmar')}>Confirmar simulación y consultar resultado</Button>
              : !i.aprobada ? <Button onClick={() => enviar('aprobar')}>Aprobar operación académica</Button>
                : paso !== 'captura' ? <Button onClick={() => setPaso('captura')}>Revisar captura PayPal</Button>
                  : <ConfirmationPanel title="Confirmar captura académica"><PrecioPago precio={o.precio} /><p>Aprobar no paga. Esta captura humana registra únicamente un pago simulado.</p>
                    <ActionGroup><Button onClick={() => enviar('capturar')}>Capturar pago académico</Button><Button variant="quiet" onClick={() => setPaso('retorno')}>Volver sin capturar</Button></ActionGroup></ConfirmationPanel>}
          {paso !== 'cierre' ? <Button variant="quiet" onClick={() => setPaso('cierre')}>Revisar cierre sin cobro</Button>
            : <ConfirmationPanel title="Cerrar intento académico"><p>No borra el historial ni cancela la cita. No se registra ningún pago.</p><Button onClick={() => enviar('cerrar')}>Cerrar sin cobro</Button></ConfirmationPanel>}
        </>}
      </fieldset>}
      {enabled && !o.liquidada && !i && !o.simulacion.puede_iniciar && <Alert><p>La obligación no permite iniciar un pago académico. Puede haber una operación en verificación o no ser exigible.</p></Alert>}
      {o.liquidada && <Alert variant="info"><p>El backend confirma la liquidación persistida. {o.origen_liquidacion === 'Simulado' ? 'Origen: simulación académica, sin verificación externa ni dinero enviado.' : 'Consulta su origen en el historial.'}</p></Alert>}
    </>}
    {failure && <Alert><p>{failure.message}</p></Alert>}
    {incierta && <Alert><p>Resultado incierto: la escritura pudo confirmarse. Consulta la obligación; no inicies otro pago.</p><Button disabled={busy} onClick={() => enviar(null, true)}>Recuperar la misma operación</Button></Alert>}
    {busy && <p role="status">Registrando operación…</p>}
    <Button variant="secondary" disabled={busy || query.loading || query.remaining > 0} onClick={query.reload}>Consultar estado guardado</Button>
  </section>;
}
