import { Outlet } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import AccessDeniedPage from '../features/auth/pages/AccessDeniedPage';

export default function RoleRoute({ roles }) {
  const { usuario } = useAuth();
  return usuario && roles.includes(usuario.rol) ? <Outlet /> : <AccessDeniedPage />;
}
