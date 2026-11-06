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
    <p>{SITUACIONES_PRECIO[cita.situacion_precio]}</p><PrecioPago precio={cita.precio} />
    {financiera && <><p>Situación financiera: <strong>{situacionObligacion(financiera)}</strong>.</p>
      {financiera.origen_liquidacion === 'Simulado' && <p>Origen académico simulado. No se ha transferido dinero ni verificado un pago externo.</p>}
      {financiera.devolucion && <p>Devolución {financiera.origen_liquidacion === 'Simulado' ? 'simulada' : ''}: {financiera.devolucion}. El recibo original se conserva.</p>}
      {financiera.recibo_academico && <DescargarRecibo id={cita.obligacion_id} rol={rol}
        id_paciente={rol === ROLES.ADMINISTRADOR ? cita.paciente.id_paciente : undefined} recibo={financiera.recibo_academico} />}
    </>}
    <p>La situación financiera no bloquea la llegada ni la atención.</p>
    {cita.obligacion_id && [ROLES.ADMINISTRADOR, ROLES.PACIENTE].includes(rol) && <Link className="button button--secondary"
      to={rol === ROLES.PACIENTE ? `/pagos/obligaciones/${cita.obligacion_id}` : `/pagos/pacientes/${cita.paciente.id_paciente}/obligaciones/${cita.obligacion_id}`}>Consultar obligación</Link>}
    {administrativo && cita.puede_asignar_monto && <AsignarMonto cita={cita} onActualizar={onActualizar} />}
  </section>;
}
