import { Link } from 'react-router-dom';
import { PROVEEDORES, usdCentavos } from '../utils/pagoFormat';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export function DevolucionPago({ devolucion }) {
  return <p>Devolución: <strong>{devolucion.estado}</strong> · {usdCentavos(devolucion.centavos_usd)}.
    {devolucion.estado !== 'Exitosa' && ' El resultado sigue pendiente de revisión; no se garantiza un plazo externo.'}</p>;
}
export default function HistorialPagos({ intentos, detallePath }) {
  return <div className="catalog-table-wrap"><table className="catalog-table" role="table"><caption>Intentos y cobros registrados por el servidor</caption>
    <thead><tr><th scope="col">Intento</th><th scope="col">Proveedor e importe</th><th scope="col">Resultado y cobros</th></tr></thead>
    <tbody>{intentos.map(i => <tr key={i.id}><td>{detallePath ? <Link to={detallePath(i.obligacion_id)}>Ver obligación</Link> : <strong>Intento registrado</strong>}
      <p>{formatearInstanteCita(i.creado_at)}</p><span className="payment-reference">{i.id}</span></td>
      <td><span className="catalog-cell-label">Proveedor e importe</span>{PROVEEDORES[i.proveedor]}<p>{usdCentavos(i.centavos_usd)}</p></td>
      <td><span className="catalog-cell-label">Resultado y cobros</span><strong>{i.estado}</strong>
        {!i.cobros.length && <p>Sin cobro verificado registrado en esta consulta.</p>}
        {i.cobros.map(c => <div key={c.id} className="payment-charge"><p>{c.aplicado ? 'Cobro aplicado' : 'Cobro no aplicado'}: {usdCentavos(c.centavos_usd)}</p>
          <p>Referencia: <span className="payment-reference">{c.referencia}</span></p>
          {c.incidencia && <p>Incidencia pendiente de revisión. No hay resolución ni compensación adicional disponible en este portal.</p>}
          {c.devolucion && <DevolucionPago devolucion={c.devolucion} />}</div>)}</td></tr>)}</tbody></table></div>;
}
