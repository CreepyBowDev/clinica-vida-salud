import { Link } from 'react-router-dom';
import { ROLES } from '../../../constants/roles';
import { SITUACIONES_PRECIO } from '../utils/pagoFormat';
import PrecioPago from './PrecioPago';
import AsignarMonto from './AsignarMonto';
import '../styles/pagos.css';

export default function FinanzasCita({ cita, rol, onActualizar }) {
  if (rol === ROLES.MEDICO || !cita.situacion_precio) return null;
  const administrativo = [ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA].includes(rol);
  return <section className="payment-appointment" aria-label="Precio de la cita"><h2>Precio de la cita</h2>
    <p>{SITUACIONES_PRECIO[cita.situacion_precio]}</p><PrecioPago precio={cita.precio} />
    <p>La situación financiera no bloquea la llegada ni la atención.</p>
    {cita.obligacion_id && [ROLES.ADMINISTRADOR, ROLES.PACIENTE].includes(rol) && <Link className="button button--secondary"
      to={rol === ROLES.PACIENTE ? `/pagos/obligaciones/${cita.obligacion_id}` : `/pagos/pacientes/${cita.paciente.id_paciente}/obligaciones/${cita.obligacion_id}`}>Consultar obligación</Link>}
    {administrativo && cita.puede_asignar_monto && <AsignarMonto cita={cita} onActualizar={onActualizar} />}
  </section>;
}
