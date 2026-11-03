import { useState } from 'react';
import { useAuth } from '../../../hooks/useAuth';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';

export default function SessionGate({ children, standalone = false }) {
  const { status, error, refreshSession } = useAuth();
  const [busy, setBusy] = useState(false);
  const Container = standalone ? 'main' : 'div';
  if (status === 'loading') return <Container className="session-gate"><div role="status">{standalone ? <h1>Comprobando tu sesión…</h1> : <p>Comprobando tu sesión…</p>}</div></Container>;
  if (status === 'unavailable') return <Container className="session-gate">
    <h1>No podemos comprobar tu acceso</h1><Alert>{error}</Alert>
    <Button loading={busy} onClick={async () => {
      setBusy(true); try { await refreshSession(); } finally { setBusy(false); }
    }}>Volver a comprobar</Button>
  </Container>;
  return children;
}
