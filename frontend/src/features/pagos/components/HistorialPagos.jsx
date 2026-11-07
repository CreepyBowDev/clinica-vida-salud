import { Link } from 'react-router-dom';
import { PROVEEDORES, usdCentavos } from '../utils/pagoFormat';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export function DevolucionPago({ devolucion }) {
  return <p className="payment-refund">Devolución: <strong>{devolucion.estado}</strong> · {usdCentavos(devolucion.centavos_usd)}.
    {devolucion.origen === 'Simulado' && ' Origen: simulación académica, sin transferencia externa.'}
    {['Pendiente', 'Procesando'].includes(devolucion.estado) && ' Aún no hay una devolución exitosa confirmada; no se garantiza un plazo externo.'}
    {devolucion.estado === 'Fallida' && ' No se confirmó una devolución exitosa. Requiere revisión; no puedes reintentar desde este portal.'}
    {devolucion.estado === 'Incierta' && ' El resultado no está confirmado. Requiere revisión, sin repetir la operación desde este portal.'}</p>;
}
export default function HistorialPagos({ intentos, detallePath }) {
  return <div className="catalog-table-wrap payment-records payment-history"><table className="catalog-table" role="table"><caption>Intentos y cobros registrados por el servidor</caption>
    <thead><tr><th scope="col">Intento</th><th scope="col">Proveedor e importe</th><th scope="col">Resultado y cobros</th></tr></thead>
    <tbody>{intentos.map(i => <tr key={i.id}><td>{detallePath ? <Link to={detallePath(i.obligacion_id)}>Ver obligación</Link> : <strong>Intento registrado</strong>}
      <p>Creado: {formatearInstanteCita(i.creado_at)}</p><p className="payment-record-meta">Actualizado: {formatearInstanteCita(i.actualizado_at)}</p>
      <p className="payment-record-meta">Referencia del intento:</p><span className="payment-reference">{i.id}</span></td>
      <td><span className="catalog-cell-label">Proveedor e importe</span>{PROVEEDORES[i.proveedor]}{i.origen === 'Simulado' && <p>Origen simulado · modalidad {i.modalidad === 'paypal' ? 'PayPal' : 'Stripe'}. Sin verificación externa.</p>}<p>{usdCentavos(i.centavos_usd)}</p></td>
       <td><span className="catalog-cell-label">Resultado y cobros</span><strong className="payment-situation">{i.estado}</strong>
        {!i.cobros.length && <p>{i.origen === 'Simulado' ? 'Sin pago simulado aplicado en esta consulta.' : 'Sin cobro verificado registrado en esta consulta.'}</p>}
        {i.cobros.map(c => <div key={c.id} className="payment-charge"><p>{c.aplicado ? 'Cobro aplicado' : 'Cobro no aplicado'}: {usdCentavos(c.centavos_usd)}</p>
           <p>Confirmado: {formatearInstanteCita(c.confirmado_at)}</p>
           <p>Referencia del cobro: <span className="payment-reference">{c.referencia}</span></p>
          {c.incidencia && <p>Incidencia pendiente de revisión. No hay resolución ni compensación adicional disponible en este portal.</p>}
          {c.devolucion && <>{i.origen === 'Simulado' && <p>Devolución académica simulada, sin transferencia de dinero.</p>}<DevolucionPago devolucion={c.devolucion} /></>}</div>)}</td></tr>)}</tbody></table></div>;
}
