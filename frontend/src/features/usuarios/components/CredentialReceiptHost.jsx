import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import Brand from '../../../components/Brand';
import Button from '../../../components/Button';
import '../styles/accounts.css';

const ReceiptContext = createContext(null);
export const useCredentialReceipt = () => useContext(ReceiptContext);

// El contexto comparte solo el callback. El secreto vive en esta frontera UI, nunca en AuthContext.
// La entrega reemplaza el Outlet para sobrevivir a la revocación de la propia sesión sin mostrar el área privada.
export default function CredentialReceiptHost() {
  const location = useLocation();
  const [receipt, setReceipt] = useState(null);
  const heading = useRef(null);
  const show = useCallback(data => {
    if (typeof data?.credencial_temporal !== 'string' || data.credencial_temporal.length !== 20
      || typeof data.id_usuario !== 'string' || !Number.isFinite(Date.parse(data.expira_en))) {
      throw new Error('No se pudo confirmar la entrega de la credencial.');
    }
    setReceipt({ id: data.id_usuario, secret: data.credencial_temporal, expires: data.expira_en, key: location.key });
  }, [location.key]);
  const current = receipt?.key === location.key ? receipt : null;
  useEffect(() => { if (receipt && !current) setReceipt(null); }, [receipt, current]);
  useEffect(() => {
    if (current) {
      document.title = 'Entrega de credencial temporal | Clínica Vida Salud';
      heading.current?.focus();
    }
  }, [current]);
  useEffect(() => {
    const clear = () => setReceipt(null);
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);

  return <ReceiptContext.Provider value={show}>
    {current ? <div className="credential-delivery"><header><Brand /></header><main className="credential-receipt">
      <h1 ref={heading} tabIndex="-1">Credencial temporal emitida</h1>
      <p>Cuenta {current.id}. Comunica esta credencial presencialmente al trabajador. Al ocultarla no podrás volver a consultarla.</p>
      <output className="credential-secret" aria-label="Credencial temporal para entrega presencial">{current.secret}</output>
      <p>Vence el <time dateTime={current.expires}>{new Intl.DateTimeFormat('es', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(current.expires))} UTC</time>. Debe ingresar con su correo y esta credencial, y establecer la contraseña definitiva antes del vencimiento.</p>
      <p>Se revocaron sus sesiones y credenciales temporales anteriores. La cuenta conserva cualquier bloqueo de acceso vigente.</p>
      <Button onClick={() => setReceipt(null)}>Ya comuniqué la credencial; ocultar</Button>
    </main></div> : <Outlet />}
  </ReceiptContext.Provider>;
}
