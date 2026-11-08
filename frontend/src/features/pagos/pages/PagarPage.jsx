import { useEffect, useRef, useState } from 'react';
import { Link, useBlocker, useLocation, useParams } from 'react-router-dom';
import PageHeading from '../../../components/PageHeading';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import SelectField from '../../../components/SelectField';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import ActionGroup from '../../../components/ActionGroup';
import { escucharCambioSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { paymentPresentation } from '../../../constants/paymentPresentation';
import { consultarPagos, operarSimulacion } from '../services/pago.service';
import { usePagoQuery } from '../hooks/usePagoQuery';
import { errorPago } from '../utils/pagoError';
import { esUuid, situacionObligacion } from '../utils/pagoFormat';
import { DEMOSTRACION_PAGO } from '../utils/demostracionPagoFixture';
import FormularioPagoDemo from '../components/FormularioPagoDemo';
import PrecioPago from '../components/PrecioPago';
import ProgresoPago from '../components/ProgresoPago';
import '../../../styles/catalogs.css';
import '../../../styles/workflows.css';
import '../styles/pagos.css';

export default function PagarPage({ suspended = false, onRiskChange }) {
  const { id } = useParams();
  return <Recorrido key={id} id={id} suspended={suspended} onRiskChange={onRiskChange} />;
}
function Recorrido({ id, suspended, onRiskChange }) {
  const location = useLocation(), entry = paymentPresentation(location.pathname, location.search, ROLES.PACIENTE);
  const query = usePagoQuery(consultarPagos, { tipo: 'detalle', id, rol: ROLES.PACIENTE }, esUuid(id));
  const [modalidad, setModalidad] = useState('stripe'), [escenario, setEscenario] = useState('completado');
  const [formularios, setFormularios] = useState(() => ({ stripe: { ...DEMOSTRACION_PAGO.stripe }, paypal: { ...DEMOSTRACION_PAGO.paypal } }));
  const [paso, setPaso] = useState('seleccion'), [busy, setBusy] = useState(false), [failure, setFailure] = useState(null), [incierta, setIncierta] = useState(false);
  const pending = useRef(null), locked = useRef(false), generation = useRef(0), heading = useRef(null);
  const [dirty, setDirty] = useState(false);
  const protectedCycle = Boolean(dirty || busy || incierta || ['revision', 'captura', 'cierre'].includes(paso));
  const blocker = useBlocker(protectedCycle);
  useEffect(() => { onRiskChange?.(protectedCycle); return () => onRiskChange?.(false); }, [onRiskChange, protectedCycle]);
  useEffect(() => {
    const clear = () => { generation.current++; locked.current = false; pending.current = null; setBusy(false); setIncierta(false); setFormularios({ stripe: { ...DEMOSTRACION_PAGO.stripe }, paypal: { ...DEMOSTRACION_PAGO.paypal } }); };
    const off = escucharCambioSesion(clear);
    return () => { generation.current++; off(); };
  }, []);
  useEffect(() => { heading.current?.focus({ preventScroll: paso === 'seleccion' }); }, [paso, query.loading]);
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
      setPaso(input.tipo === 'iniciar' ? 'salida' : input.tipo === 'cerrar' ? 'cerrado' : 'retorno'); query.reload();
    } catch (error) {
      if (seq !== generation.current) return;
      const f = errorPago(error), uncertain = error.pagoEnviado !== false && (!f.status || f.status >= 500);
      setFailure(f); setIncierta(uncertain);
      if (!uncertain) pending.current = null;
      query.reload();
    } finally { if (seq === generation.current) { locked.current = false; setBusy(false); } }
  }
  const o = query.data, i = o?.intento_actual?.proveedor === 'simulado' ? o.intento_actual : null;
  const enabled = o?.simulacion?.habilitada, blocked = busy || incierta || suspended || !entry.valid;
  const etapa = o?.liquidada ? 3 : i || incierta ? 2 : paso === 'revision' ? 1 : 0;
  const titulo = o?.liquidada ? 'Resultado consultado' : paso === 'salida' && i ? 'Salida simulada' : paso === 'captura' && i ? 'Revisar captura' : paso === 'cierre' && i ? 'Revisar cierre' : i ? 'Continuar intento académico' : paso === 'revision' ? 'Revisar importe' : 'Recorrido académico';
  return <><section hidden={suspended} inert={suspended || undefined} className="workspace-page catalog-page payments-page payment-demo payment-journey">
    <Link className="back-link" to={`/pagos/obligaciones/${id}${entry.search}`}>Volver a la obligación e historial</Link>
    {!entry.valid && <Alert><h1>Vista de pagos no disponible</h1><p>Revisa la opción solicitada antes de continuar.</p><Link to={`/pagos/obligaciones/${id}/pagar`}>Volver al recorrido académico</Link></Alert>}
    <div hidden={!entry.valid} inert={!entry.valid || undefined}>
    <PageHeading title="Pagar" description="Recorrido académico vinculado a la obligación de tu cita." />
    <Alert variant="warning"><p>Pago académico simulado. Registra un resultado local, sin enviar dinero ni contactar con Stripe o PayPal. No introduzcas datos reales.</p></Alert>
    <p>Los formularios son representaciones locales, no Checkout ni autenticación auténticos.</p>
    {!esUuid(id) ? <Alert><p>No se encontró el recurso solicitado.</p></Alert> : query.loading ? <p className="payment-journey-status" role="status">Consultando obligación…</p> : query.failure ? <Alert><p>{query.failure.message}</p></Alert> : o ? <>
      <ProgresoPago actual={etapa} />
      <div className="payment-journey-grid">
      <section className="payment-journey-summary" aria-label="Resumen de la cita"><h2>Cita {o.cita.codigo_cita}</h2>
        <p>Estado asistencial: {o.cita.estado}.</p><p className="payment-situation"><strong>{situacionObligacion(o)}</strong></p>
        <PrecioPago precio={o.precio} /><p>Precio histórico de esta cita. La simulación no envía dinero a un proveedor.</p></section>
      <section className="payment-journey-step" aria-label="Paso actual">
      <h2 ref={heading} tabIndex={-1}>{titulo}</h2>
      {!enabled && <Alert><p>La consulta no habilita el recorrido académico. No informa una causa detallada.</p></Alert>}
      {paso === 'cerrado' && !i && !o.liquidada && !incierta && <Alert variant="info"><p>Intento académico cerrado sin cobro. Conserva su historial y no modifica la cita. La consulta actual determina si puedes iniciar otro recorrido.</p></Alert>}
      {enabled && !o.liquidada && o.exigible && !i && o.simulacion.puede_iniciar && <fieldset disabled={blocked}>
        {paso !== 'revision' ? <>
          <div className="payment-journey-selection">
          <SelectField label="Modalidad" value={modalidad} onChange={e => { setDirty(true); setModalidad(e.target.value); setPaso('seleccion'); }}><option value="stripe">Stripe (representación)</option><option value="paypal">PayPal (representación)</option></SelectField>
          <SelectField label="Escenario académico" value={escenario} onChange={e => { setDirty(true); setEscenario(e.target.value); }}><option value="completado">Completar operación</option><option value="pendiente">Permanecer pendiente</option></SelectField>
          <p>{modalidad === 'paypal' ? 'PayPal: aprobar no paga. Después deberás revisar y solicitar la captura humana.' : 'Stripe: después del retorno deberás confirmar la simulación expresamente.'} {escenario === 'pendiente' ? 'El intento permanecerá pendiente, sin consultas automáticas.' : 'Elegir este escenario no confirma un cobro: habrá que consultar el resultado guardado.'}</p>
          </div>
          <FormularioPagoDemo key={modalidad} persistente proveedor={modalidad} datos={formularios[modalidad]}
            onChange={datos => { setDirty(true); setFormularios(v => ({ ...v, [modalidad]: datos })); }} onRevisar={() => setPaso('revision')} />
        </> : <ConfirmationPanel title="Confirmar importe"><PrecioPago precio={o.precio} />
          <p>Modalidad: <strong>{modalidad === 'paypal' ? 'PayPal' : 'Stripe'} representativo</strong>. Escenario: {escenario === 'pendiente' ? 'permanecer pendiente' : 'completar operación'}.</p>
          <p>Revisa el importe histórico y la tasa académica 7:1 antes de iniciar con {modalidad === 'paypal' ? 'PayPal' : 'Stripe'} representativo.</p>
          <ActionGroup><Button onClick={() => enviar('iniciar')}>Iniciar pago académico</Button><Button variant="quiet" onClick={() => setPaso('seleccion')}>Volver al formulario</Button></ActionGroup>
        </ConfirmationPanel>}
      </fieldset>}
      {enabled && i && o.exigible && !o.liquidada && <fieldset disabled={blocked}>
        <div className="payment-journey-state"><h3>Intento académico guardado</h3><p>Modalidad {i.modalidad === 'paypal' ? 'PayPal' : 'Stripe'}. {i.aprobada ? 'Aprobación registrada, sin captura.' : 'Sin cobro aplicado.'}</p><p className="payment-reference">Referencia local: {i.id}</p></div>
        {paso === 'salida' ? <><p>Sigues en la clínica. No se abre ningún proveedor.</p><Button onClick={() => setPaso('retorno')}>Volver al portal</Button></> : <>
          <p>El retorno no implica éxito. El resultado procede del backend, nunca de esta transición visual.</p>
          {paso !== 'cierre' && (i.escenario === 'pendiente' ? <Alert variant="warning"><p>Resultado pendiente. No equivale a éxito ni fallo; no hay polling y no se habilita otro pago.</p></Alert>
            : i.modalidad === 'stripe' ? <div className="payment-journey-decision"><h3>Confirmación humana Stripe</h3><p>Esta acción solicita registrar el resultado académico. Solo la consulta posterior puede acreditar la liquidación.</p><Button onClick={() => enviar('confirmar')}>Confirmar simulación y consultar resultado</Button></div>
              : !i.aprobada ? <div className="payment-journey-decision"><h3>Aprobación PayPal, todavía sin pago</h3><p>Registrar la aprobación no captura ni liquida. Después podrás revisar el importe para solicitar la captura.</p><Button onClick={() => enviar('aprobar')}>Aprobar operación académica</Button></div>
                : paso !== 'captura' ? <Button onClick={() => setPaso('captura')}>Revisar captura PayPal</Button>
                  : <ConfirmationPanel title="Confirmar captura académica"><PrecioPago precio={o.precio} /><p>Aprobar no paga. Esta captura humana registra únicamente un pago simulado.</p>
                    <ActionGroup><Button onClick={() => enviar('capturar')}>Capturar pago académico</Button><Button variant="quiet" onClick={() => setPaso('retorno')}>Volver sin capturar</Button></ActionGroup></ConfirmationPanel>)}
          <div className="payment-journey-close">{paso !== 'cierre' ? <Button variant="quiet" onClick={() => setPaso('cierre')}>Revisar cierre sin cobro</Button>
            : <ConfirmationPanel title="Cerrar intento académico"><p>No borra el historial ni cancela la cita. No se registra ningún pago.</p><ActionGroup><Button onClick={() => enviar('cerrar')}>Cerrar sin cobro</Button><Button variant="quiet" onClick={() => setPaso('retorno')}>Volver sin cerrar</Button></ActionGroup></ConfirmationPanel>}
          </div>
        </>}
      </fieldset>}
      {enabled && !o.liquidada && !i && !o.simulacion.puede_iniciar && <Alert><p>La consulta no habilita iniciar un pago académico. No informa una causa detallada. Consulta el estado guardado o vuelve al historial.</p></Alert>}
      {o.liquidada && <Alert variant="info"><p>El backend confirma la liquidación persistida. {o.origen_liquidacion === 'Simulado' ? 'Origen: simulación académica, sin verificación externa ni dinero enviado.' : 'Consulta su origen en el historial.'}</p></Alert>}
      {!o.exigible && !o.liquidada && <Alert><p>La obligación no es exigible. No se ofrece un nuevo pago.</p></Alert>}
      </section></div>
    </> : <Alert><p>No hay información de la obligación para mostrar.</p></Alert>}
    {failure && <Alert><p>{failure.message}</p></Alert>}
    {incierta && <Alert><p>Resultado incierto: la escritura pudo confirmarse. Consulta la obligación; no inicies otro pago.</p><Button disabled={busy || suspended || !entry.valid} onClick={() => enviar(null, true)}>Recuperar la misma operación</Button></Alert>}
    {busy && <p role="status">Registrando operación…</p>}
    {query.remaining > 0 && <p role="status">Espera {query.remaining} s antes de volver a consultar. No se consulta automáticamente.</p>}
    <Button variant="secondary" disabled={busy || suspended || !entry.valid || query.loading || query.remaining > 0} onClick={query.reload}>Consultar estado guardado</Button>
    </div>
  </section>
    {blocker.state === 'blocked' && <Alert variant="warning"><p>Salir del recorrido puede perder la preparación o recuperación en memoria. Cambiar solo la presentación conserva el proceso.</p><ActionGroup><Button onClick={() => blocker.reset()}>Permanecer aquí</Button><Button variant="secondary" onClick={() => blocker.proceed()}>Salir y consultar después</Button></ActionGroup></Alert>}
  </>;
}
