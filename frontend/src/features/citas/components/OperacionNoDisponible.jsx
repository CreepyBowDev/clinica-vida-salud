import { Link } from 'react-router-dom';
import Alert from '../../../components/Alert';

export default function OperacionNoDisponible({ to }) {
  return <section className="workspace-page page-message"><h1>Operación no disponible</h1>
    <Alert><p>La operación indicada no es válida para este recorrido o tu perfil. No se ha realizado ninguna operación.</p></Alert>
    <Link to={to}>Volver a seleccionar una operación</Link></section>;
}
