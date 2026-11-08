import { Link } from 'react-router-dom';
import QueryMessage from '../../../components/QueryMessage';

export default function ClinicalModeError({ to = '/historia-clinica' }) {
  return <section className="workspace-page"><QueryMessage title="Operación no disponible" description="El recorrido solicitado no es válido para esta pantalla o tu rol." /><Link to={to}>Volver a una entrada válida</Link></section>;
}
