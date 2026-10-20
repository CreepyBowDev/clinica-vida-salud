import { useRef, useState } from 'react';
import { useAuth } from '../../../hooks/useAuth';
import { usePasswordSubmission } from '../hooks/usePasswordSubmission';
import { validarNuevaClave } from '../utils/passwordValidation';
import PasswordField from '../../../components/PasswordField';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import PasswordFailure from '../components/PasswordFailure';

export default function ChangePasswordPage() {
  const { updatePassword } = useAuth();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [success, setSuccess] = useState(false);
  const form = useRef(null);
  const { busy, failure, remaining, execute, report } = usePasswordSubmission(form);

  async function submit(event) {
    event.preventDefault();
    const fields = validarNuevaClave(password, confirmation);
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados.', fields }); return; }
    setSuccess(false);
    await execute(async () => {
      try {
        await updatePassword({ current_password: current, password, password_confirmation: confirmation });
        setSuccess(true);
      } finally { setCurrent(''); setPassword(''); setConfirmation(''); }
    });
  }

  return <section className="workspace-page">
    <header className="page-heading"><h1>Cambiar contraseña</h1><p>Actualiza la contraseña de tu cuenta.</p></header>
    <div className="security-panel"><div className="security-description"><h2>Una contraseña solo tuya</h2>
      <p>Usa una frase de al menos 12 caracteres que puedas recordar. No es necesario agregar símbolos ni combinaciones difíciles.</p>
      <p>Al guardar, se cerrarán tus sesiones anteriores. Esta sesión se renovará para que puedas continuar.</p></div>
      <form ref={form} className="security-form" aria-label="Cambiar contraseña" aria-busy={busy} onSubmit={submit}>
        {success && <Alert variant="success">Contraseña actualizada. Puedes continuar con tu sesión actual.</Alert>}
        <PasswordFailure failure={failure} remaining={remaining} />
        <PasswordField label="Contraseña actual" name="current_password" autoComplete="current-password" value={current}
          busy={busy} onChange={e => setCurrent(e.target.value)} error={failure?.fields.current_password} />
        <PasswordField label="Nueva contraseña" name="password" autoComplete="new-password" minLength={12} hint="Mínimo 12 caracteres. Puedes usar una frase."
          value={password} busy={busy} onChange={e => setPassword(e.target.value)} error={failure?.fields.password} />
        <PasswordField label="Confirmar nueva contraseña" name="password_confirmation" autoComplete="new-password" value={confirmation}
          busy={busy} onChange={e => setConfirmation(e.target.value)} error={failure?.fields.password_confirmation} />
        <Button type="submit" loading={busy} disabled={remaining > 0}>{busy ? 'Guardando contraseña…' : remaining > 0 ? `Espera ${remaining} s` : 'Guardar contraseña'}</Button>
      </form>
    </div>
  </section>;
}
