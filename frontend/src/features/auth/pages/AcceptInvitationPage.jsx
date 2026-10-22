import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { aceptarInvitacion } from '../services/invitation.service';
import { usePasswordSubmission } from '../hooks/usePasswordSubmission';
import { validarNuevaClave } from '../utils/passwordValidation';
import PasswordField from '../../../components/PasswordField';
import FormSection from '../../../components/FormSection';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import Icon from '../../../components/Icon';
import PasswordFailure from '../components/PasswordFailure';
import LogoutButton from '../components/LogoutButton';

export default function AcceptInvitationPage({ enlace }) {
  const [token, setToken] = useState(() => enlace.token);
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [success, setSuccess] = useState(false);
  const { status, refreshSession } = useAuth();
  const form = useRef(null);
  const heading = useRef(null);
  const { busy, failure, remaining, execute, report } = usePasswordSubmission(form);
  const hasSession = ['authenticated', 'restricted'].includes(status);
  useEffect(() => {
    enlace.token = null;
    document.title = 'Activar acceso del personal | Clínica Vida Salud';
    return () => { enlace.token = null; };
  }, [enlace]);
  useEffect(() => { if (success || !token) heading.current?.focus(); }, [success, token]);

  async function submit(event) {
    event.preventDefault();
    if (hasSession || !token) return;
    const fields = validarNuevaClave(password, confirmation);
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados.', fields }); return; }
    await execute(async () => {
      try {
        await aceptarInvitacion({ token, password, password_confirmation: confirmation });
        setToken(null); setSuccess(true);
        await refreshSession();
      } catch (error) {
        if (error.response?.status === 422 && error.response?.data?.error?.code === 'INVITACION_INVALIDA') setToken(null);
        if (error.response?.status === 409 && error.response?.data?.error?.code === 'AUTH_SESION_EXISTENTE') await refreshSession();
        throw error;
      } finally { setPassword(''); setConfirmation(''); }
    });
  }

  return <div className="login-panel auth-task-panel">
    <span className={`session-symbol${success ? ' session-symbol--success' : ''}`} aria-hidden="true"><Icon name={success ? 'check' : !token ? 'info' : 'mail'} width="28" height="28" /></span>
    <h1 ref={heading} tabIndex="-1">{success ? 'Tu acceso está listo' : !token ? 'Invitación no disponible' : 'Activa tu acceso al equipo'}</h1>
    {success ? <><p className="login-intro">Tu correo quedó verificado y tu contraseña fue establecida. Inicia sesión para ingresar al portal.</p>
      <Link className="button button--primary auth-back-link" to="/login">Iniciar sesión</Link></>
      : !token ? <><p className="login-intro">Abre la invitación más reciente de tu correo. Si venció, ya se usó o no está disponible, solicita otra a Administración.</p>
        <div className="auth-links"><Link className="auth-back-link" to="/login">Volver a iniciar sesión</Link></div></>
        : hasSession ? <div className="recovery-guidance"><Alert variant="info">Hay una sesión activa en este navegador. Debes cerrarla antes de aceptar la invitación.</Alert>
          <LogoutButton /><div className="auth-links"><Link className="auth-back-link" to={status === 'restricted' ? '/clave-obligatoria' : '/inicio'}>Volver a mi sesión</Link></div></div>
          : <><p className="login-intro">Establece tu primera contraseña para verificar el correo y activar tu cuenta del personal.</p>
            <p className="auth-guidance">La invitación es de un solo uso y tiene una vigencia de 24 horas.</p>
            <form ref={form} className="recovery-form" aria-label="Aceptar invitación del personal" aria-busy={busy} onSubmit={submit}>
              <PasswordFailure failure={failure} remaining={remaining} />
              <FormSection title="Tu contraseña de acceso">
              <PasswordField label="Nueva contraseña" autoComplete="new-password" name="password" minLength={12} hint="Mínimo 12 caracteres. Puedes usar una frase."
                value={password} busy={busy} onChange={event => setPassword(event.target.value)} error={failure?.fields.password} />
              <PasswordField label="Confirmar nueva contraseña" autoComplete="new-password" name="password_confirmation" value={confirmation}
                busy={busy} onChange={event => setConfirmation(event.target.value)} error={failure?.fields.password_confirmation} />
              </FormSection>
              <Button type="submit" className="full-width" loading={busy} disabled={remaining > 0}>{busy ? 'Activando acceso…' : remaining > 0 ? `Espera ${remaining} s` : 'Establecer contraseña y activar'}</Button>
            </form><div className="auth-links"><Link className="auth-back-link" to="/login">Volver a iniciar sesión</Link></div></>}
  </div>;
}
