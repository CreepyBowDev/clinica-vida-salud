import { useEffect, useState } from 'react';
import { Link, useBlocker } from 'react-router-dom';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import ActionGroup from '../../../components/ActionGroup';
import SelectField from '../../../components/SelectField';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import { PROVEEDORES } from '../utils/pagoFormat';
import { useOperacionPago } from '../hooks/useOperacionPago';
import { DevolucionPago } from './HistorialPagos';

export default function OperacionPago({ obligacion, intento, onConsultar, onRiskChange, retorno = false, secondary = false, suspended = false }) {
  const op = useOperacionPago(), [proveedor, setProveedor] = useState(''), [decision, setDecision] = useState(null);
  const response = op.response, data = response?.data;
  const actual = data ? { id: data.id, proveedor: data.proveedor, estado: data.estado } : intento ?? obligacion?.intento_actual;
  const protectedCycle = Boolean(proveedor || decision || op.busy || op.uncertain || op.remaining > 0 || response);
  const blocker = useBlocker(protectedCycle);
  useEffect(() => { onRiskChange?.(protectedCycle); return () => onRiskChange?.(false); }, [onRiskChange, protectedCycle]);
  const disabled = suspended || op.busy || op.remaining > 0 || op.uncertain || op.paused;
  const iniciar = !op.needsReview && !op.stale && !response && !op.uncertain && !op.paused && obligacion?.puede_iniciar_pago;
  const captura = !retorno && actual?.proveedor === 'paypal' && data && response.status === 200 && data.estado === 'RequiereAccion' && !data.accion && data.obligacion.exigible
    && !data.obligacion.liquidada && !data.operacion_en_verificacion && !op.needsReview && !op.stale;
  function revisar(tipo) { setDecision(tipo); }
  function enviar() {
    const tipo = decision; setDecision(null);
    op.ejecutar(tipo === 'iniciar' ? { tipo, id: obligacion.id, proveedor, revision_pago: obligacion.revision_pago } : { tipo, id: actual.id });
  }
  return <><section hidden={suspended} inert={suspended || undefined} className={`catalog-panel payment-operation${!iniciar && !actual ? ' payment-operation--unavailable' : ''}`} aria-label="Operaciones del titular" aria-busy={op.busy}>
    <h2>{retorno ? 'Consultar el resultado del intento' : 'Proveedor externo de prueba'}</h2>
    <p>{secondary ? 'Alternativa externa al pago académico. ' : ''}Stripe Checkout test o PayPal Sandbox, sin dinero real. El retorno del navegador y la aprobación de PayPal no acreditan un pago.</p>
    {actual && <p className="payment-record-meta">Proveedor: {PROVEEDORES[actual.proveedor]}<br />Referencia del intento: <span className="payment-reference">{actual.id}</span></p>}
    {op.failure && <Alert><p>{op.failure.message}</p></Alert>}
    {op.paused && <Alert variant="warning"><p>El contexto cambió. Abre nuevamente el detalle antes de operar.</p></Alert>}
    {op.stale && <p>Volviste a la pestaña. Consulta nuevamente el resultado para obtener acciones vigentes.</p>}
    {op.busy && <p role="status">Consultando al servidor. No envíes otra solicitud.</p>}
    {op.remaining > 0 && <p role="status">Espera {op.remaining} segundos antes de volver a solicitarlo.</p>}
    {op.uncertain && <Alert variant="warning"><p>No se confirmó el resultado. No inicies otro pago ni cambies de proveedor. Recupera la misma solicitud; se conservarán su clave y sus datos mientras permanezcas en esta pantalla. Tras recargar, consulta la obligación.</p></Alert>}
    {data && <div className="payment-operation-result" role="status"><h3>Resultado consultado</h3><p>Estado verificado por el servidor: <strong>{data.estado}</strong>.</p>
      {data.aplicado && <p>Cobro verificado y aplicado a esta obligación.</p>}
      {data.estado === 'Exitoso' && !data.aplicado && <p>Este intento no tiene un cobro aplicado. Consulta el historial y sus incidencias.</p>}
      {(response.status === 202 || data.operacion_en_verificacion) && <p>Verificación pendiente. No equivale a fallo ni habilita otro proveedor. Consulta nuevamente cuando termine la espera.</p>}
      {data.devolucion && <DevolucionPago devolucion={data.devolucion} />}
      {data.estado === 'Cerrado' && <p>El cierre del intento no cancela la cita. Consulta la obligación para comprobar si puedes elegir otro proveedor.</p>}
    </div>}
    {iniciar && <>
      <SelectField label="Proveedor de prueba" value={proveedor} onChange={e => { setProveedor(e.target.value); setDecision(null); }}>
        <option value="">Selecciona un proveedor</option>{obligacion.proveedores_disponibles.map(p => <option key={p} value={p}>{PROVEEDORES[p]}</option>)}
      </SelectField>
      <Button variant={secondary || decision ? 'secondary' : 'primary'} disabled={disabled || !obligacion.proveedores_disponibles.includes(proveedor)} onClick={() => revisar('iniciar')}>Revisar inicio de pago</Button>
    </>}
    {!iniciar && !actual && !op.busy && <p>No hay una acción externa disponible en esta consulta. La respuesta no indica una causa específica.</p>}
    <ActionGroup>
      {op.uncertain && <Button disabled={suspended || op.busy || op.remaining > 0} onClick={op.recuperar}>Recuperar misma solicitud</Button>}
      {actual && !op.uncertain && <Button variant={!decision && !data?.accion && !captura ? 'primary' : 'secondary'} disabled={disabled} onClick={() => { setDecision(null); op.ejecutar({ tipo: 'resultado', id: actual.id }); }}>Consultar resultado</Button>}
      {data?.accion && !disabled && <a className={`button button--${decision ? 'secondary' : 'primary'}`} href={data.accion.url} rel="noreferrer" referrerPolicy="no-referrer">Continuar en {PROVEEDORES[data.proveedor]}</a>}
      {captura && <Button variant={decision ? 'secondary' : 'primary'} disabled={disabled} onClick={() => revisar('captura')}>Revisar captura PayPal</Button>}
      {actual && ['Preparando', 'RequiereAccion', 'Procesando', 'Incierto'].includes(actual.estado) && !op.needsReview && <Button variant="secondary" disabled={disabled} onClick={() => revisar('cierre')}>Solicitar cierre del intento</Button>}
      {onConsultar && !op.uncertain && <Button variant="quiet" disabled={disabled} onClick={onConsultar}>Consultar obligación vigente</Button>}
      {retorno && data && !op.uncertain && !op.busy && <Link className="button button--secondary" to={`/pagos/obligaciones/${data.obligacion_id}?vista=pagar`}>Revisar importe y obligación</Link>}
    </ActionGroup>
    {decision && <ConfirmationPanel title={decision === 'captura' ? 'Confirmar captura PayPal' : decision === 'cierre' ? 'Confirmar solicitud de cierre' : 'Confirmar inicio de pago'}>
      <p>{decision === 'captura' ? 'Solicitarás al servidor capturar el importe USD histórico mostrado. Aprobar en PayPal no era todavía pagar.'
        : decision === 'cierre' ? 'Solo se solicita cerrar este intento. La cita no se cancela. Si el resultado es incierto, no podrás cambiar de proveedor.'
          : `Se iniciará un intento con ${PROVEEDORES[proveedor]} por el importe USD histórico revisado. No cambies de proveedor si el resultado queda incierto.`}</p>
      <ActionGroup><Button disabled={disabled} onClick={enviar}>{decision === 'captura' ? 'Capturar pago con PayPal' : decision === 'cierre' ? 'Solicitar cierre' : 'Iniciar pago de prueba'}</Button>
        <Button variant="quiet" disabled={suspended || op.busy} onClick={() => setDecision(null)}>Volver a revisar</Button></ActionGroup>
    </ConfirmationPanel>}
  </section>
    {blocker.state === 'blocked' && <Alert variant="warning"><p>La salida puede perder la recuperación en memoria; cambiar solo la vista de esta obligación conserva el proceso. La operación puede seguir pendiente en el servidor.</p>
      <ActionGroup><Button onClick={() => blocker.reset()}>Permanecer aquí</Button><Button variant="secondary" onClick={() => blocker.proceed()}>Salir y consultar después</Button></ActionGroup></Alert>}
  </>;
}
