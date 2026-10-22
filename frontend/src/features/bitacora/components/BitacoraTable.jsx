import { Link } from 'react-router-dom';
import { etiquetaActor, etiquetaResultado, formatearFechaEvento } from '../utils/bitacoraFormat';

export default function BitacoraTable({ eventos }) {
  return <div className="catalog-table-wrap query-table"><table className="catalog-table bitacora-table" role="table">
    <caption>Eventos ordenados por fecha de registro, más recientes primero. Fecha del hecho en America/La_Paz.</caption>
    <thead role="rowgroup"><tr role="row">{['Evento y fecha del hecho', 'Módulo y operación', 'Responsable', 'Cuenta objetivo', 'Resultado', 'Consulta'].map(label => <th key={label} scope="col" role="columnheader">{label}</th>)}</tr></thead>
    <tbody role="rowgroup">{eventos.map(item => <tr key={item.id_evento} role="row">
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Evento y fecha del hecho</span><strong>{item.tipo_evento}</strong><span className="bitacora-cell-line">Evento #{item.id_evento}</span><time className="bitacora-cell-line" dateTime={item.ocurrido_en}>{formatearFechaEvento(item.ocurrido_en)}</time></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Módulo y operación</span>{item.modulo}<span className="bitacora-cell-line">{item.operacion}</span></td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Responsable</span>{etiquetaActor(item.tipo_actor)}
        {item.id_usuario_responsable && <span className="bitacora-cell-line">Usuario #{item.id_usuario_responsable}</span>}
        {item.rol_responsable && <span className="bitacora-cell-line">Rol histórico: {item.rol_responsable}</span>}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Cuenta objetivo</span>{item.id_usuario_objetivo ? <>Cuenta #{item.id_usuario_objetivo}{item.rol_objetivo && <span className="bitacora-cell-line">Rol histórico: {item.rol_objetivo}</span>}</> : 'Sin cuenta objetivo'}</td>
      <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Resultado</span><span className={`catalog-status${item.resultado === 'EXITO' ? '' : ' catalog-status--inactive'}`}>{etiquetaResultado(item.resultado)}</span></td>
      <td role="cell" className="query-table-action"><Link to={`/administracion/bitacora/${item.id_evento}`} aria-label={`Ver evento ${item.id_evento}`}>Ver detalle</Link></td>
    </tr>)}</tbody>
  </table></div>;
}
