import { useState } from 'react';
import { useAuth } from '../../../hooks/useAuth';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';

export default function SessionGate({ children }) {
  const { status, error, refreshSession } = useAuth();
  const [busy, setBusy] = useState(false);
  if (status === 'loading') return <div className="session-gate" role="status"><p>Comprobando tu sesión…</p></div>;
  if (status === 'unavailable') return <div className="session-gate">
    <h1>No podemos comprobar tu acceso</h1><Alert>{error}</Alert>
    <Button loading={busy} onClick={async () => {
      setBusy(true); try { await refreshSession(); } finally { setBusy(false); }
    }}>Volver a comprobar</Button>
  </div>;
  return children;
}
