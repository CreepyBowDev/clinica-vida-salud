import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import Icon from '../../../components/Icon';
import { useAuth } from '../../../hooks/useAuth';
import { describirError } from '../../../utils/httpError';

export default function LoginPage() {
  const { login, refreshSession, notice, error: sessionError } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [visible, setVisible] = useState(false);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState(null);
  const [waitUntil, setWaitUntil] = useState(0);
  const [remaining, setRemaining] = useState(0);
  const form = useRef(null);
  const inFlight = useRef(false);

  useEffect(() => {
    if (!waitUntil) return;
    const tick = () => setRemaining(Math.max(0, Math.ceil((waitUntil - Date.now()) / 1000)));
    tick();
    const timer = setInterval(tick, 1000);
    return () => clearInterval(timer);
  }, [waitUntil]);

  useEffect(() => { document.title = 'Iniciar sesión | Clínica Vida Salud'; }, []);

  function report(error) {
    const described = describirError(error);
    setFailure(described);
    if (described.retryAfter) {
      setWaitUntil(Date.now() + described.retryAfter * 1000);
      setRemaining(described.retryAfter);
    }
    requestAnimationFrame(() => {
      const invalid = form.current?.querySelector('[aria-invalid="true"]');
      invalid?.focus();
    });
  }

  async function run(operation) {
    if (inFlight.current) return;
    inFlight.current = true;
    setBusy(true);
    setFailure(null);
    try { await operation(); } catch (error) { report(error); }
    finally { inFlight.current = false; setBusy(false); }
  }

  async function submit(event) {
    event.preventDefault();
    if (remaining > 0 || inFlight.current) return;
    await run(async () => {
      try {
        await login({ correo_acceso: email.trim(), password });
      } finally {
        setPassword('');
        setVisible(false);
      }
    });
  }

  const errorBlock = failure && <Alert>
    <p>{failure.message}</p>
    {remaining > 0 && <p>Podrás volver a intentarlo en {remaining} s.</p>}
  </Alert>;

  return <div className="login-panel">
    <div className="login-heading"><h1>Bienvenido de nuevo</h1><p className="login-intro">Ingresa a tu cuenta de Clínica Vida Salud.</p></div>
    <form ref={form} onSubmit={submit} aria-label="Iniciar sesión" aria-busy={busy}>
      {notice === 'clave_temporal_actualizada' && <Alert variant="success">Contraseña definitiva establecida. Inicia sesión con tu nueva contraseña.</Alert>}
      {notice === 'correo_actualizado' && <Alert variant="success">Correo de acceso actualizado. Tus sesiones anteriores se cerraron. Inicia sesión con tu nuevo correo y tu contraseña.</Alert>}
      {sessionError && !failure && <Alert>{sessionError}</Alert>}
      {errorBlock}
      <FormField label="Correo electrónico" name="correo_acceso" type="email" autoComplete="username"
        placeholder="nombre@ejemplo.com" required maxLength={254} value={email} readOnly={busy}
        onChange={(event) => setEmail(event.target.value)} error={failure?.fields.correo_acceso} />
      <FormField label="Contraseña" name="password" type={visible ? 'text' : 'password'} autoComplete="current-password"
        placeholder="Ingresa tu contraseña" required maxLength={1024} value={password} readOnly={busy}
        onChange={(event) => setPassword(event.target.value)} error={failure?.fields.password}
        trailing={<button type="button" className="password-toggle" aria-label={visible ? 'Ocultar contraseña' : 'Mostrar contraseña'}
          aria-pressed={visible} onClick={() => setVisible(!visible)} disabled={busy}><Icon name={visible ? 'eyeOff' : 'eye'} /></button>} />
      <Link className="forgot-link" to="/olvide-mi-contrasena">¿Olvidaste tu contraseña?</Link>
      <Button type="submit" className="full-width login-submit" loading={busy} disabled={remaining > 0}>
        {busy ? 'Comprobando acceso…' : remaining > 0 ? `Espera ${remaining} s` : 'Iniciar sesión'}
      </Button>
    </form>
    {failure && (!failure.status || failure.status === 409) && <Button variant="secondary" className="full-width"
      loading={busy} onClick={() => run(refreshSession)}>Comprobar mi sesión</Button>}
    <div className="login-assistance"><Icon name="lock" /><p>Acceso personal para pacientes<br className="desktop-break" /> y equipo de la clínica.</p></div>
    <p className="first-access">¿Es tu primer acceso?<br /><Link to="/registro">Crear cuenta de Paciente</Link><br /><Link to="/vincular-ficha">Ya tengo un código de activación</Link></p>
  </div>;
}
