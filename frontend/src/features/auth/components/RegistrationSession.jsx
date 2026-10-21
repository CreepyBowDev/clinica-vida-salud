import { Link } from 'react-router-dom';
import Alert from '../../../components/Alert';
import LogoutButton from './LogoutButton';
import { useAuth } from '../../../hooks/useAuth';

export default function RegistrationSession({ children }) {
  const { status } = useAuth();
  if (['authenticated', 'restricted'].includes(status)) return <div className="login-panel auth-task-panel">
    <h1>Ya hay una sesión abierta</h1>
    <Alert variant="info">Cierra la sesión antes de registrar o activar una cuenta de Paciente.</Alert>
    {status === 'restricted' && <p className="recovery-note">Si cierras sin completar el cambio obligatorio, se invalida tu credencial temporal.</p>}
    <LogoutButton /><div className="auth-links"><Link className="auth-back-link" to={status === 'restricted' ? '/clave-obligatoria' : '/inicio'}>Volver a mi sesión</Link></div>
  </div>;
  return children;
}
