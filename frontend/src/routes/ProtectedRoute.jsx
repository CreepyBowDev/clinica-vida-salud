import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import SessionGate from '../features/auth/components/SessionGate';

export default function ProtectedRoute({ restrictedOnly = false }) {
  const { status } = useAuth();
  const location = useLocation();
  return <SessionGate>{(() => {
    if (status === 'anonymous') return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    if (status === 'restricted' && !restrictedOnly) return <Navigate to="/clave-obligatoria" replace />;
    if (status === 'authenticated' && restrictedOnly) return <Navigate to="/inicio" replace />;
    return <Outlet />;
  })()}</SessionGate>;
}
