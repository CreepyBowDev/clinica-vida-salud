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

export default function OperacionPago({ obligacion, intento, onConsultar, onRiskChange, retorno = false }) {
  const op = useOperacionPago(), [proveedor, setProveedor] = useState(''), [decision, setDecision] = useState(null);
  const response = op.response, data = response?.data;
  const actual = data ? { id: data.id, proveedor: data.proveedor, estado: data.estado } : intento ?? obligacion?.intento_actual;
  const blocker = useBlocker(Boolean(op.busy || op.uncertain));
  useEffect(() => { onRiskChange?.(Boolean(op.busy || op.uncertain)); return () => onRiskChange?.(false); }, [onRiskChange, op.busy, op.uncertain]);
  const disabled = op.busy || op.remaining > 0 || op.uncertain || op.paused;
  const iniciar = !op.needsReview && !op.stale && !response && !op.uncertain && !op.paused && obligacion?.puede_iniciar_pago;
  function revisar(tipo) { setDecision(tipo); }
  function enviar() {
    const tipo = decision; setDecision(null);
    op.ejecutar(tipo === 'iniciar' ? { tipo, id: obligacion.id, proveedor, revision_pago: obligacion.revision_pago } : { tipo, id: actual.id });
  }
  return <section className="catalog-panel payment-operation" aria-label="Operaciones del titular" aria-busy={op.busy}>
    <h2>{retorno ? 'Consultar el resultado del intento' : 'Pago de prueba'}</h2>
    <p>Solo pruebas académicas, sin dinero real. El retorno del navegador y la aprobación de PayPal no acreditan un pago.</p>
    {op.failure && <Alert><p>{op.failure.message}</p></Alert>}
    {op.paused && <Alert variant="warning"><p>El contexto cambió. Abre nuevamente el detalle antes de operar.</p></Alert>}
    {op.stale && <p>Volviste a la pestaña. Consulta nuevamente el resultado para obtener acciones vigentes.</p>}
    {op.busy && <p role="status">Consultando al servidor. No envíes otra solicitud.</p>}
    {op.remaining > 0 && <p role="status">Espera {op.remaining} segundos antes de volver a solicitarlo.</p>}
    {op.uncertain && <Alert variant="warning"><p>No se confirmó el resultado. No inicies otro pago ni cambies de proveedor. Recupera la misma solicitud; se conservarán su clave y sus datos mientras permanezcas en esta pantalla. Tras recargar, consulta la obligación.</p></Alert>}
    {data && <div role="status"><p>Estado verificado por el servidor: <strong>{data.estado}</strong>.</p>
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
      <Button disabled={disabled || !obligacion.proveedores_disponibles.includes(proveedor)} onClick={() => revisar('iniciar')}>Revisar inicio de pago</Button>
    </>}
    {!iniciar && !actual && !op.busy && <p>No hay una acción de pago elegible en esta consulta. Actualiza el detalle para comprobar la disponibilidad.</p>}
    <ActionGroup>
      {op.uncertain && <Button disabled={op.busy || op.remaining > 0} onClick={op.recuperar}>Recuperar misma solicitud</Button>}
      {actual && !op.uncertain && <Button variant="secondary" disabled={disabled} onClick={() => { setDecision(null); op.ejecutar({ tipo: 'resultado', id: actual.id }); }}>Consultar resultado</Button>}
      {data?.accion && !disabled && <a className="button button--primary" href={data.accion.url} rel="noreferrer" referrerPolicy="no-referrer">Continuar en {PROVEEDORES[data.proveedor]}</a>}
      {!retorno && actual?.proveedor === 'paypal' && data && response.status === 200 && data.estado === 'RequiereAccion' && !data.accion && data.obligacion.exigible
        && !data.obligacion.liquidada && !data.operacion_en_verificacion && !op.needsReview && !op.stale && <Button disabled={disabled} onClick={() => revisar('captura')}>Revisar captura PayPal</Button>}
      {actual && ['Preparando', 'RequiereAccion', 'Procesando', 'Incierto'].includes(actual.estado) && !op.needsReview && <Button variant="secondary" disabled={disabled} onClick={() => revisar('cierre')}>Solicitar cierre del intento</Button>}
      {onConsultar && !op.uncertain && <Button variant="quiet" disabled={disabled} onClick={onConsultar}>Consultar obligación vigente</Button>}
      {retorno && data && !op.uncertain && !op.busy && <Link className="button button--secondary" to={`/pagos/obligaciones/${data.obligacion_id}`}>Revisar importe y obligación</Link>}
    </ActionGroup>
    {decision && <ConfirmationPanel title={decision === 'captura' ? 'Confirmar captura PayPal' : decision === 'cierre' ? 'Confirmar solicitud de cierre' : 'Confirmar inicio de pago'}>
      <p>{decision === 'captura' ? 'Solicitarás al servidor capturar el importe USD histórico mostrado. Aprobar en PayPal no era todavía pagar.'
        : decision === 'cierre' ? 'Solo se solicita cerrar este intento. La cita no se cancela. Si el resultado es incierto, no podrás cambiar de proveedor.'
          : `Se iniciará un intento con ${PROVEEDORES[proveedor]} por el importe USD histórico revisado. No cambies de proveedor si el resultado queda incierto.`}</p>
      <ActionGroup><Button disabled={disabled} onClick={enviar}>{decision === 'captura' ? 'Capturar pago con PayPal' : decision === 'cierre' ? 'Solicitar cierre' : 'Iniciar pago de prueba'}</Button>
        <Button variant="quiet" disabled={op.busy} onClick={() => setDecision(null)}>Volver a revisar</Button></ActionGroup>
    </ConfirmationPanel>}
    {blocker.state === 'blocked' && <Alert variant="warning"><p>Al salir perderás la recuperación en memoria. La operación puede seguir pendiente en el servidor.</p>
      <ActionGroup><Button onClick={() => blocker.reset()}>Permanecer aquí</Button><Button variant="secondary" onClick={() => blocker.proceed()}>Salir y consultar después</Button></ActionGroup></Alert>}
  </section>;
}
