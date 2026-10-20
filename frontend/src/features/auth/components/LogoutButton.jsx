import { useRef, useState } from 'react';
import { useAuth } from '../../../hooks/useAuth';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';

export default function LogoutButton() {
  const { logout } = useAuth();
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);
  const inFlight = useRef(false);
  return <div className="logout-control">
    {failed && <Alert>No pudimos confirmar el cierre. Tu sesión aún podría estar activa. Vuelve a intentarlo.</Alert>}
    <Button variant="secondary" loading={busy} onClick={async () => {
      if (inFlight.current) return;
      inFlight.current = true; setBusy(true); setFailed(false);
      try { await logout(); } catch { setFailed(true); }
      finally { inFlight.current = false; setBusy(false); }
    }}>{busy ? 'Cerrando sesión…' : 'Cerrar sesión'}</Button>
  </div>;
}
