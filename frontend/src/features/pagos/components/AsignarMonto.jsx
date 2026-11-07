import { useEffect, useId, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import Alert from '../../../components/Alert';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import PrecioPago from './PrecioPago';
import { proponerMonto, confirmarMonto } from '../services/pago.service';
import { errorPago } from '../utils/pagoError';
import { montoEntrada } from '../utils/pagoFormat';

export default function AsignarMonto({ cita, onActualizar }) {
  const [monto, setMonto] = useState(''), [state, setState] = useState({}), [remaining, setRemaining] = useState(0);
  const lock = useRef(false), sequence = useRef(0), until = useRef(0);
  const montoId = useId(), review = useRef(null), feedback = useRef(null);
  const [errorMonto, setErrorMonto] = useState('');
  useEffect(() => {
    if (state.failure?.fields.monto_bob) document.getElementById(montoId)?.focus();
    else if (state.failure || state.success) feedback.current?.focus();
    else if (state.propuesta) review.current?.focus();
  }, [state.failure, state.success, state.propuesta, montoId]);
  useEffect(() => {
    const clear = () => { sequence.current++; setMonto(''); setState({ paused: true }); };
    const unsubscribe = escucharCambioSesion(clear); window.addEventListener('pagehide', clear);
    return () => { sequence.current++; unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => { if (!remaining) return; const timer = setInterval(() => setRemaining(Math.max(0, Math.ceil((until.current - Date.now()) / 1000))), 1000); return () => clearInterval(timer); }, [remaining]);
  async function ejecutar(confirmar = false) {
    if (lock.current || Date.now() < until.current || state.paused) return;
    if (!confirmar) {
      try { montoEntrada(monto); setErrorMonto(''); }
      catch (error) { setErrorMonto(error.message); document.getElementById(montoId)?.focus(); return; }
    }
    lock.current = true; const seq = ++sequence.current;
    setState(v => ({ ...v, busy: true, failure: null }));
    try {
      const result = confirmar ? await confirmarMonto(cita.id_cita, state.propuesta.propuesta) : await proponerMonto(cita.id_cita, monto);
      if (seq !== sequence.current) return;
      setState(confirmar ? { success: true } : { propuesta: result });
      if (confirmar) onActualizar();
    } catch (error) {
      if (seq !== sequence.current) return;
      const failure = errorPago(error);
      until.current = Date.now() + failure.retryAfter * 1000; setRemaining(failure.retryAfter);
      const uncertain = confirmar && error.pagoEnviado !== false && (!failure.status || failure.status >= 500);
      setState(v => ({ failure, uncertain, propuesta: uncertain ? v.propuesta : null, needsReview: confirmar && !uncertain }));
    } finally { if (seq === sequence.current) lock.current = false; }
  }
  return <section className="catalog-panel payment-assignment" aria-label="Asignación de monto" aria-busy={state.busy}>
    <h2>Asignar monto</h2><p>Una sola asignación, sin edición posterior. No cambia la atención ni el estado de la cita.</p>
    <div ref={feedback} tabIndex="-1">{state.failure && <Alert><p>{state.failure.message}</p>{!state.failure.status && !state.uncertain && <p>Verifica el formato y el rango del monto.</p>}</Alert>}
    {remaining > 0 && <p role="status">Espera {remaining} segundos.</p>}
    {state.uncertain && <Alert variant="warning"><p>La asignación podría haberse guardado. Consulta la cita; no prepares otro monto para resolver esta incertidumbre. Puedes recuperar exclusivamente la misma propuesta.</p></Alert>}
    {state.success && <p role="status">Asignación confirmada por el servidor. Consulta la cita vigente.</p>}</div>
    {state.busy && <p role="status">{state.propuesta ? 'Confirmando la misma propuesta…' : 'Preparando el precio para revisión…'}</p>}
    {state.paused && <p role="status">La sesión o la página ha cambiado. Consulta la cita vigente antes de continuar.</p>}
    {!state.success && !state.propuesta && !state.needsReview && !state.paused && <form noValidate onSubmit={e => { e.preventDefault(); ejecutar(); }}>
      <FormField id={montoId} label="Monto de la consulta (BOB)" inputMode="decimal" value={monto} disabled={state.busy} onChange={e => { setMonto(e.target.value); setErrorMonto(''); }} autoComplete="off" required
        error={errorMonto || state.failure?.fields.monto_bob} hint="De Bs 7,00 a Bs 7.000,00; máximo dos decimales. Escribe sin separador de miles." />
      <p className="payment-record-meta">Revisar no asigna el precio. Primero verás BOB, tasa y USD del servidor.</p>
      <Button type="submit" loading={state.busy} disabled={remaining > 0}>Revisar monto</Button></form>}
    {state.propuesta && <div ref={review} tabIndex="-1" className="payment-assignment-review"><ConfirmationPanel title="Revisar monto de la cita"><p>Cita {cita.codigo_cita}. El servidor revalidará sus condiciones al confirmar.</p>
       <PrecioPago precio={state.propuesta.precio} /><ActionGroup>
        <Button disabled={state.busy || remaining > 0} onClick={() => ejecutar(true)}>{state.uncertain ? 'Recuperar misma asignación' : 'Confirmar asignación de monto'}</Button>
         {!state.uncertain && <Button variant="quiet" disabled={state.busy} onClick={() => { setState({}); requestAnimationFrame(() => document.getElementById(montoId)?.focus()); }}>Volver a editar</Button>}</ActionGroup></ConfirmationPanel></div>}
    <Button variant="secondary" disabled={state.busy || state.uncertain || remaining > 0} onClick={onActualizar}>Consultar cita vigente</Button>
  </section>;
}
