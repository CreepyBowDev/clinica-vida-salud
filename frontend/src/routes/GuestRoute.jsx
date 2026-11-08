import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { destinoTrasLogin } from '../features/auth/utils/loginDestination';
import SessionGate from '../features/auth/components/SessionGate';

export default function GuestRoute() {
  const { status, usuario } = useAuth();
  const location = useLocation();
  return <SessionGate>{status === 'authenticated'
    ? <Navigate to={destinoTrasLogin(location.state?.from, usuario?.rol)} replace />
    : status === 'restricted' ? <Navigate to="/clave-obligatoria" replace /> : <Outlet />}</SessionGate>;
}
