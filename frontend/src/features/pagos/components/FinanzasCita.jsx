import { Link } from 'react-router-dom';
import { ROLES } from '../../../constants/roles';
import { SITUACIONES_PRECIO, situacionObligacion } from '../utils/pagoFormat';
import DescargarRecibo from './DescargarRecibo';
import PrecioPago from './PrecioPago';
import AsignarMonto from './AsignarMonto';
import '../styles/pagos.css';

export default function FinanzasCita({ cita, rol, onActualizar }) {
  if (rol === ROLES.MEDICO || !cita.situacion_precio) return null;
  const administrativo = [ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA].includes(rol);
  const financiera = [ROLES.ADMINISTRADOR, ROLES.PACIENTE].includes(rol) ? cita.situacion_financiera : null;
  return <section className="payment-appointment" aria-label="Precio de la cita"><h2>Precio de la cita</h2>
    <div className="payment-appointment-grid"><div className="payment-appointment-price">
      <h3>{SITUACIONES_PRECIO[cita.situacion_precio]}</h3>
      {cita.situacion_precio === 'PendienteAsignacion' && <p>El personal asignará el monto de esta cita. Todavía no hay un precio para pagar; no es una deuda de importe cero.</p>}
      {cita.situacion_precio === 'NoIncorporada' && <p>Esta cita no forma parte del módulo financiero. No se le atribuye una deuda ni un precio retroactivo.</p>}
      {cita.precio && <><p className="payment-record-meta">Precio histórico de esta cita, sin edición posterior.</p><PrecioPago precio={cita.precio} /></>}
    </div><div className="payment-appointment-status">
    {financiera && <><h3>Situación financiera</h3><p><strong className="payment-situation">{situacionObligacion(financiera)}</strong></p>
      {financiera.origen_liquidacion === 'Simulado' && <p>Origen académico simulado. No se ha transferido dinero ni verificado un pago externo.</p>}
      {financiera.origen_liquidacion && financiera.origen_liquidacion !== 'Simulado' && <p>Origen del pago: {financiera.origen_liquidacion}.</p>}
    </>}
    <p className="payment-record-meta">La situación financiera no bloquea la llegada ni la atención.</p>
    {cita.obligacion_id && [ROLES.ADMINISTRADOR, ROLES.PACIENTE].includes(rol) && <Link className="button button--secondary"
      to={rol === ROLES.PACIENTE ? `/pagos/obligaciones/${cita.obligacion_id}` : `/pagos/pacientes/${cita.paciente.id_paciente}/obligaciones/${cita.obligacion_id}`}>Consultar obligación</Link>}
    </div></div>
    {administrativo && cita.puede_asignar_monto && <AsignarMonto cita={cita} onActualizar={onActualizar} />}
    {financiera?.recibo_academico && <DescargarRecibo id={cita.obligacion_id} rol={rol}
      id_paciente={rol === ROLES.ADMINISTRADOR ? cita.paciente.id_paciente : undefined} recibo={financiera.recibo_academico} />}
    {financiera?.devolucion && <section className="payment-appointment-refund" aria-label="Devolución posterior"><h3>Devolución posterior</h3>
      <p>Devolución {financiera.origen_liquidacion === 'Simulado' ? 'simulada' : ''}: <strong>{financiera.devolucion}</strong>.</p>
      {['Pendiente', 'Procesando'].includes(financiera.devolucion) && <p>Aún no hay una devolución exitosa confirmada; no se garantiza un plazo externo.</p>}
      {financiera.devolucion === 'Fallida' && <p>No se confirmó una devolución exitosa. Requiere revisión; no puedes reintentar desde este portal.</p>}
      {financiera.devolucion === 'Incierta' && <p>El resultado no está confirmado. Requiere revisión, sin repetir la operación desde este portal.</p>}
      <p className="payment-record-meta">El recibo original se conserva. La devolución se consulta por separado y no modifica ese documento.</p></section>}
  </section>;
}
