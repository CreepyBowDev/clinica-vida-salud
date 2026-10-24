import { useEffect, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import PasswordCreationFields from '../components/PasswordCreationFields';
import FormSection from '../../../components/FormSection';
import ActionGroup from '../../../components/ActionGroup';
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

  return <div className="login-panel auth-task-panel">
    <span className="session-symbol" aria-hidden="true"><Icon name="lock" width="28" height="28" /></span>
    <h1>Cambia tu contraseña</h1>
    <p className="login-intro">Tu acceso es restringido. Debes establecer una contraseña definitiva antes de usar el portal.</p>
    <p className="auth-guidance">Debes completar este paso antes de que venza la credencial temporal. Al guardar se cerrará esta sesión y tendrás que ingresar con tu nueva contraseña.</p>
    <form ref={form} className="recovery-form" aria-label="Establecer contraseña definitiva" aria-busy={busy} onSubmit={submit}>
      <PasswordFailure failure={failure} remaining={remaining} />
      <FormSection title="Contraseña definitiva">
      <PasswordCreationFields password={password} confirmation={confirmation} busy={busy} fields={failure?.fields}
        onPasswordChange={event => setPassword(event.target.value)} onConfirmationChange={event => setConfirmation(event.target.value)} />
      </FormSection>
      <Button type="submit" className="full-width" loading={busy} disabled={checking || remaining > 0}>{busy ? 'Guardando contraseña…' : remaining > 0 ? `Espera ${remaining} s` : 'Establecer contraseña definitiva'}</Button>
    </form>
    {failure && <Button variant="secondary" disabled={busy} loading={checking} onClick={async () => {
      setChecking(true); try { await refreshSession(); } finally { setChecking(false); }
    }}>Comprobar vigencia de sesión</Button>}
    <div className="restricted-exit">
      {exit ? <><Alert variant="warning">Cerrar sin completar invalida la credencial temporal. Necesitarás otra emisión de Administración para ingresar; no se restaura tu contraseña anterior.</Alert>
        <ActionGroup><LogoutButton /><Button variant="quiet" onClick={() => setExit(false)}>Continuar con el cambio</Button></ActionGroup></>
        : <Button variant="quiet" disabled={busy || checking} onClick={() => setExit(true)}>Cerrar sin completar el cambio</Button>}
    </div>
  </div>;
}
