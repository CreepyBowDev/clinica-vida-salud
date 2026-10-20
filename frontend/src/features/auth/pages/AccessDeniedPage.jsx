import { Link } from 'react-router-dom';
import Icon from '../../../components/Icon';

export default function AccessDeniedPage() {
  return <section className="page-message">
    <Icon name="lock" width="32" height="32" /><h1>Acceso no permitido</h1>
    <p>Tu cuenta está autenticada, pero esta sección corresponde a otro rol.</p>
    <Link className="button button--secondary" to="/inicio">Volver a mi inicio</Link>
  </section>;
}
