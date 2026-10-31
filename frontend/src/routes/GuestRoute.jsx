import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { destinoSeguro } from '../constants/roles';
import SessionGate from '../features/auth/components/SessionGate';

export default function GuestRoute() {
  const { status, usuario } = useAuth();
  const location = useLocation();
  return <SessionGate>{status === 'authenticated'
    ? <Navigate to={destinoSeguro(location.state?.from, usuario?.rol)} replace />
    : status === 'restricted' ? <Navigate to="/clave-obligatoria" replace /> : <Outlet />}</SessionGate>;
}
