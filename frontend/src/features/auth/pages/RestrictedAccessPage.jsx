import { useEffect, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PasswordField from '../../../components/PasswordField';
import Icon from '../../../components/Icon';
import LogoutButton from '../components/LogoutButton';
import PasswordFailure from '../components/PasswordFailure';
import { useAuth } from '../../../hooks/useAuth';
import { usePasswordSubmission } from '../hooks/usePasswordSubmission';
import { validarNuevaClave } from '../utils/passwordValidation';

export default function RestrictedAccessPage() {
  const { completeTemporaryPassword, refreshSession } = useAuth();
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [exit, setExit] = useState(false);
  const [checking, setChecking] = useState(false);
  const form = useRef(null);
  const { busy, failure, remaining, execute, report } = usePasswordSubmission(form);
  useEffect(() => { document.title = 'Cambio obligatorio de contraseña | Clínica Vida Salud'; }, []);

  async function submit(event) {
    event.preventDefault();
    const fields = validarNuevaClave(password, confirmation);
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados.', fields }); return; }
    await execute(async () => {
      try {
        await completeTemporaryPassword({ password, password_confirmation: confirmation });
      } finally { setPassword(''); setConfirmation(''); }
    });
  }

  return <div className="login-panel">
    <span className="session-symbol" aria-hidden="true"><Icon name="lock" width="28" height="28" /></span>
    <h1>Cambia tu contraseña</h1>
    <p className="login-intro">Tu acceso es restringido. Debes establecer una contraseña definitiva antes de usar el portal.</p>
    <p className="recovery-note">Debes completar este paso antes de que venza la credencial temporal. Al guardar se cerrará esta sesión y tendrás que ingresar con tu nueva contraseña.</p>
    <form ref={form} className="recovery-form" aria-label="Establecer contraseña definitiva" aria-busy={busy} onSubmit={submit}>
      <PasswordFailure failure={failure} remaining={remaining} />
      <PasswordField label="Nueva contraseña" name="password" autoComplete="new-password" minLength={12} hint="Mínimo 12 caracteres. Puedes usar una frase."
        value={password} busy={busy} onChange={event => setPassword(event.target.value)} error={failure?.fields.password} />
      <PasswordField label="Confirmar nueva contraseña" name="password_confirmation" autoComplete="new-password" value={confirmation}
        busy={busy} onChange={event => setConfirmation(event.target.value)} error={failure?.fields.password_confirmation} />
      <Button type="submit" className="full-width" loading={busy} disabled={checking || remaining > 0}>{busy ? 'Guardando contraseña…' : remaining > 0 ? `Espera ${remaining} s` : 'Establecer contraseña definitiva'}</Button>
    </form>
    {failure && <Button variant="secondary" disabled={busy} loading={checking} onClick={async () => {
      setChecking(true); try { await refreshSession(); } finally { setChecking(false); }
    }}>Comprobar vigencia de sesión</Button>}
    <div className="restricted-exit">
      {exit ? <><Alert variant="warning">Cerrar sin completar invalida la credencial temporal. Necesitarás otra emisión de Administración para ingresar; no se restaura tu contraseña anterior.</Alert>
        <LogoutButton /><Button variant="quiet" onClick={() => setExit(false)}>Continuar con el cambio</Button></>
        : <Button variant="quiet" disabled={busy || checking} onClick={() => setExit(true)}>Cerrar sin completar el cambio</Button>}
    </div>
  </div>;
}
