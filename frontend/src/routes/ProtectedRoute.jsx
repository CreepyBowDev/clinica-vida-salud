import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import SessionGate from '../features/auth/components/SessionGate';
import { destinoLogin } from '../features/auth/utils/loginDestination';

export default function ProtectedRoute({ restrictedOnly = false }) {
  const { status } = useAuth();
  const location = useLocation();
  return <SessionGate standalone={!restrictedOnly}>{(() => {
    if (status === 'anonymous') return <Navigate to="/login" replace state={{ from: destinoLogin(location.pathname, location.search) }} />;
    if (status === 'restricted' && !restrictedOnly) return <Navigate to="/clave-obligatoria" replace />;
    if (status === 'authenticated' && restrictedOnly) return <Navigate to="/inicio" replace />;
    return <Outlet />;
  })()}</SessionGate>;
}
