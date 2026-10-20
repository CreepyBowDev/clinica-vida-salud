import { useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';
import FormField from '../../../components/FormField';
import PasswordFailure from '../components/PasswordFailure';
import RegistrationSession from '../components/RegistrationSession';
import { useRegistrationSubmission } from '../hooks/useRegistrationSubmission';
import { reenviarCodigoRegistro, verificarRegistro } from '../services/registration.service';
import { useAuth } from '../../../hooks/useAuth';

export default function VerifyRegistrationPage() {
  return <RegistrationSession><VerificationForm /></RegistrationSession>;
}
function VerificationForm() {
  const { state } = useLocation();
  const [received, setReceived] = useState(Boolean(state?.solicitudRecibida));
  const [codigo, setCodigo] = useState('');
  const [success, setSuccess] = useState(false);
  const form = useRef(null);
  const heading = useRef(null);
  const { refreshSession } = useAuth();
  const { busy, failure, remaining, execute, report } = useRegistrationSubmission(form);
  useEffect(() => { document.title = 'Verificar correo de Paciente | Clínica Vida Salud'; }, []);
  useEffect(() => { if (success) heading.current?.focus(); }, [success]);
  async function run(request) {
    await execute(async () => {
      try { await request(); }
      catch (error) {
        if (error.response?.data?.error?.code === 'AUTH_SESION_EXISTENTE') await refreshSession();
        throw error;
      } finally { setCodigo(''); }
    });
  }
  return <div className="login-panel">
    <h1 ref={heading} tabIndex="-1">{success ? 'Tu cuenta está activada' : 'Verifica tu correo'}</h1>
    {success ? <><p className="login-intro">Tu correo quedó verificado. Inicia sesión con el correo y la contraseña de tu solicitud para ingresar al portal.</p><Link className="button button--primary auth-back-link" to="/login">Iniciar sesión</Link></> : <>
      <p className="login-intro">Introduce el código de seis dígitos más reciente de tu correo. Este paso sirve para una cuenta nueva y para vincular una ficha existente.</p>
      {received && <Alert variant="info">Solicitud recibida. Si corresponde, recibirás un código. Esta respuesta no confirma el envío. Si no lo recibes, solicita reenvío o acude a Recepción para asistencia, acceso o vinculación.</Alert>}
      <p className="recovery-note">Continúa en este mismo navegador. El código dura hasta diez minutos y admite cinco errores. El reenvío requiere al menos 60 segundos entre envíos y admite hasta cinco por hora; no amplía el plazo del proceso ni del código presencial.</p>
      <form ref={form} className="recovery-form" aria-label="Verificar correo de Paciente" aria-busy={busy} onSubmit={event => {
        event.preventDefault();
        if (!/^\d{6}$/.test(codigo)) { report({ message: 'Revisa el código del correo.', fields: { codigo: 'Introduce exactamente seis dígitos, incluidos los ceros iniciales.' } }); return; }
        run(async () => { await verificarRegistro({ codigo }); setSuccess(true); await refreshSession(); });
      }}>
        <PasswordFailure failure={failure} remaining={remaining} />
        <FormField name="codigo" label="Código del correo" type="text" inputMode="numeric" autoComplete="one-time-code" minLength={6} maxLength={6} required value={codigo} readOnly={busy} onChange={event => setCodigo(event.target.value)} error={failure?.fields.codigo} />
        <Button type="submit" className="full-width" loading={busy} disabled={remaining > 0}>{busy ? 'Procesando solicitud…' : remaining > 0 ? `Espera ${remaining} s` : 'Verificar y activar mi cuenta'}</Button>
        <Button variant="secondary" className="full-width auth-back-link" disabled={busy || remaining > 0} onClick={() => run(async () => { await reenviarCodigoRegistro(); setReceived(true); })}>Solicitar otro código de correo</Button>
      </form>
      <p className="recovery-note">Si perdiste el contexto de este navegador o venció la autorización de Recepción, solicita asistencia presencial. No se puede recuperar un proceso solo con CI o correo.</p>
      <Link className="auth-back-link" to="/registro">Volver al registro</Link><Link className="auth-back-link" to="/login">Volver a iniciar sesión</Link>
    </>}
  </div>;
}
