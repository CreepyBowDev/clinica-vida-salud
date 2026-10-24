import { useRef, useState } from 'react';
import { useAuth } from '../../../hooks/useAuth';
import { usePasswordSubmission } from '../hooks/usePasswordSubmission';
import { validarNuevaClave } from '../utils/passwordValidation';
import PasswordField from '../../../components/PasswordField';
import PasswordCreationFields from '../components/PasswordCreationFields';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import PasswordFailure from '../components/PasswordFailure';
import PageHeading from '../../../components/PageHeading';
import FormSection from '../../../components/FormSection';
import ActionGroup from '../../../components/ActionGroup';
import '../styles/access.css';

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

  return <section className="workspace-page security-page">
    <PageHeading title="Cambiar contraseña" description="Actualiza la contraseña de tu cuenta." />
    <div className="security-panel"><div className="security-description"><h2>Una contraseña solo tuya</h2>
      <p>Usa al menos 12 caracteres e incluye una mayúscula, una minúscula, un número y un carácter especial.</p>
      <p>Al guardar, se cerrarán tus sesiones anteriores. Esta sesión se renovará para que puedas continuar.</p></div>
      <form ref={form} className="security-form" aria-label="Cambiar contraseña" aria-busy={busy} onSubmit={submit}>
        {success && <Alert variant="success">Contraseña actualizada. Puedes continuar con tu sesión actual.</Alert>}
        <PasswordFailure failure={failure} remaining={remaining} />
        <FormSection title="Confirma tu identidad"><PasswordField label="Contraseña actual" name="current_password" autoComplete="current-password" value={current}
          busy={busy} onChange={e => setCurrent(e.target.value)} error={failure?.fields.current_password} /></FormSection>
        <FormSection title="Tu nueva contraseña">
        <PasswordCreationFields password={password} confirmation={confirmation} busy={busy} fields={failure?.fields}
          onPasswordChange={e => setPassword(e.target.value)} onConfirmationChange={e => setConfirmation(e.target.value)} /></FormSection>
        <ActionGroup className="form-actions"><Button type="submit" loading={busy} disabled={remaining > 0}>{busy ? 'Guardando contraseña…' : remaining > 0 ? `Espera ${remaining} s` : 'Guardar contraseña'}</Button></ActionGroup>
      </form>
    </div>
  </section>;
}
