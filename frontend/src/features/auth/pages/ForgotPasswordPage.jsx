import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { solicitarRecuperacion } from '../services/password.service';
import { usePasswordSubmission } from '../hooks/usePasswordSubmission';
import FormField from '../../../components/FormField';
import Button from '../../../components/Button';
import Icon from '../../../components/Icon';
import PasswordFailure from '../components/PasswordFailure';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [submitted, setSubmitted] = useState(false);
  const form = useRef(null);
  const heading = useRef(null);
  const { busy, failure, remaining, execute } = usePasswordSubmission(form);
  useEffect(() => { document.title = 'Recuperar acceso | Clínica Vida Salud'; }, []);
  useEffect(() => { if (submitted) heading.current?.focus(); }, [submitted]);

  return <div className="login-panel auth-task-panel">
    <span className="session-symbol" aria-hidden="true"><Icon name={submitted ? 'mail' : 'lock'} width="28" height="28" /></span>
    <h1 ref={heading} tabIndex="-1">{submitted ? 'Solicitud recibida' : 'Recupera tu acceso'}</h1>
    {submitted ? <>
      <p className="login-intro">Si corresponde, recibirás un enlace para recuperar tu contraseña.</p>
      <div className="auth-guidance"><p>Revisa tu bandeja de entrada y la carpeta de correo no deseado. El enlace es válido durante 60 minutos y se puede usar una sola vez.</p>
        <p>Si solicitas otro enlace, el anterior dejará de ser válido.</p></div>
      <Button variant="secondary" className="full-width" onClick={() => setSubmitted(false)}>Hacer otra solicitud</Button>
    </> : <>
      <p className="login-intro">Introduce el correo de acceso de tu cuenta. Si corresponde, te enviaremos un enlace de recuperación.</p>
      <form ref={form} className="recovery-form" aria-label="Solicitar recuperación" aria-busy={busy} onSubmit={async event => {
        event.preventDefault();
        await execute(async () => { await solicitarRecuperacion({ correo_acceso: email.trim() }); setSubmitted(true); });
      }}>
        <PasswordFailure failure={failure} remaining={remaining} />
        <FormField label="Correo electrónico" name="correo_acceso" type="email" autoComplete="email" required maxLength={254}
          value={email} readOnly={busy} onChange={e => setEmail(e.target.value)} error={failure?.fields.correo_acceso} />
        <Button type="submit" className="full-width" loading={busy} disabled={remaining > 0}>{busy ? 'Procesando solicitud…' : remaining > 0 ? `Espera ${remaining} s` : 'Solicitar enlace'}</Button>
      </form>
      <p className="auth-guidance">La recuperación no activa cuentas desactivadas.</p>
    </>}
    <div className="auth-links"><Link className="auth-back-link" to="/login">Volver a iniciar sesión</Link></div>
  </div>;
}
