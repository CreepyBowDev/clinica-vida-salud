import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { usePasswordSubmission } from '../hooks/usePasswordSubmission';
import { validarNuevaClave } from '../utils/passwordValidation';
import PasswordField from '../../../components/PasswordField';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import PasswordFailure from '../components/PasswordFailure';
import LogoutButton from '../components/LogoutButton';

export default function ResetPasswordPage({ enlace }) {
  const [token, setToken] = useState(() => enlace.token);
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [success, setSuccess] = useState(false);
  const { updatePassword, status } = useAuth();
  const form = useRef(null);
  const heading = useRef(null);
  const { busy, failure, remaining, execute, report } = usePasswordSubmission(form);

  useEffect(() => {
    enlace.token = null;
    document.title = 'Nueva contraseña | Clínica Vida Salud';
    return () => { enlace.token = null; };
  }, [enlace]);
  useEffect(() => { if (success) heading.current?.focus(); }, [success]);

  async function submit(event) {
    event.preventDefault();
    const fields = validarNuevaClave(password, confirmation);
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados.', fields }); return; }
    await execute(async () => {
      try {
        await updatePassword({ token, password, password_confirmation: confirmation }, true);
        setToken(null); setSuccess(true);
      } catch (error) {
        if (error.response?.status === 422 && error.response?.data?.error?.code === 'RECUPERACION_INVALIDA') setToken(null);
        throw error;
      } finally { setPassword(''); setConfirmation(''); }
    });
  }

  return <div className="login-panel">
    <h1 ref={heading} tabIndex="-1">{success ? 'Contraseña actualizada' : !token ? 'Enlace no disponible' : 'Elige una nueva contraseña'}</h1>
    {success ? <>
      <p className="login-intro">Las sesiones anteriores de la cuenta recuperada se han invalidado. Vuelve a iniciar sesión con tu nueva contraseña.</p>
      {['authenticated', 'restricted'].includes(status) ? <div className="recovery-guidance">
        <Alert variant="info">Hay otra sesión activa en este navegador. Puedes conservarla o cerrarla antes de ingresar con la cuenta recuperada.</Alert>
        <Link className="auth-back-link" to={status === 'restricted' ? '/clave-obligatoria' : '/inicio'}>Volver a mi sesión</Link><LogoutButton />
      </div> : <Link className="button button--primary auth-back-link" to="/login">Iniciar sesión</Link>}
    </> : !token ? <>
      <p className="login-intro">Abre el enlace más reciente de tu correo. Si venció, ya se usó o no está disponible, solicita uno nuevo.</p>
      <Link className="button button--primary auth-back-link" to="/olvide-mi-contrasena">Solicitar otro enlace</Link>
    </> : <>
      <p className="login-intro">Usa una contraseña de al menos 12 caracteres para recuperar el acceso a tu cuenta.</p>
      <form ref={form} className="recovery-form" aria-label="Establecer nueva contraseña" aria-busy={busy} onSubmit={submit}>
        <PasswordFailure failure={failure} remaining={remaining} />
        <PasswordField label="Nueva contraseña" name="password" autoComplete="new-password" minLength={12} hint="Mínimo 12 caracteres. Puedes usar una frase."
          value={password} busy={busy} onChange={e => setPassword(e.target.value)} error={failure?.fields.password} />
        <PasswordField label="Confirmar nueva contraseña" name="password_confirmation" autoComplete="new-password" value={confirmation}
          busy={busy} onChange={e => setConfirmation(e.target.value)} error={failure?.fields.password_confirmation} />
        <Button type="submit" className="full-width" loading={busy} disabled={remaining > 0}>{busy ? 'Guardando contraseña…' : remaining > 0 ? `Espera ${remaining} s` : 'Guardar nueva contraseña'}</Button>
      </form>
    </>}
    {!success && <Link className="auth-back-link" to="/login">Volver a iniciar sesión</Link>}
  </div>;
}
