import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import PatientFailure from '../components/PatientFailure';
import { useEmailChangeSubmission } from '../hooks/useEmailChangeSubmission';
import { solicitarCambioCorreo, reenviarCambioCorreo, verificarCambioCorreo } from '../services/cambioCorreo.service';
import { consultarMiPerfil } from '../services/perfil.service';
import '../styles/patients.css';

const deliveryMessages = {
  aceptado: 'El proveedor aceptó el correo de verificación. Esto no confirma su entrega al buzón.',
  simulado: 'Envío simulado. No se envió un correo externo.',
  fallido: 'No se confirmó el envío. Este código no permite completar el cambio. Solicita reenvío cuando esté habilitado.',
  pendiente: 'El envío está pendiente de confirmación. No se puede asegurar que haya un código utilizable. Comprueba tu correo o solicita reenvío cuando corresponda.',
};

export default function ChangeEmailPage() {
  const { usuario, completeEmailChange } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [delivery, setDelivery] = useState(null);
  const [uncertain, setUncertain] = useState(false);
  const [checked, setChecked] = useState(false);
  const root = useRef(null);
  const heading = useRef(null);
  const { busy, failure, remaining, sendRemaining, report, run } = useEmailChangeSubmission(root);
  useEffect(() => {
    const clear = () => { setPassword(''); setCode(''); setConfirm(false); setDelivery(null); };
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  const disabled = Boolean(busy) || uncertain;
  async function submitRequest(event) {
    event.preventDefault(); if (disabled || sendRemaining) return;
    const fields = {};
    if (!email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) fields.correo_acceso = 'Introduce un correo electrónico válido.';
    if (email.trim().toLowerCase() === usuario.correo_acceso.toLowerCase()) fields.correo_acceso = 'Introduce un correo distinto del vigente.';
    if (!password) fields.current_password = 'Introduce tu contraseña actual.';
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados.', fields }); return; }
    if (!confirm) { setConfirm(true); return; }
    setChecked(false); setCode(''); setDelivery(null);
    const result = await run('request', async () => {
      try { return await solicitarCambioCorreo({ correo_acceso: email, current_password: password }); }
      finally { setPassword(''); setConfirm(false); }
    });
    handleResult(result);
  }
  function handleResult(result) {
    if (result?.data) { setDelivery(result.data); requestAnimationFrame(() => heading.current?.focus()); }
    if (result?.failure?.uncertain) setUncertain(true);
  }
  return <section ref={root} className="workspace-page patients-page">
    <Link className="patients-back" to="/mi-perfil">Volver a mi perfil</Link>
    <header className="page-heading"><h1>Cambiar correo de acceso</h1><p>Verifica tu nuevo correo antes de utilizarlo para iniciar sesión.</p></header>
    <div className="patients-panel"><dl className="patients-data"><div><dt>Correo de acceso vigente</dt><dd>{usuario.correo_acceso}</dd></div></dl>
      <p className="patients-note">Tu correo anterior se conserva hasta confirmar el código. El cambio no modifica tu correo de contacto, contraseña, ficha ni historial. Al completarlo se cerrarán todas tus sesiones y tendrás que volver a iniciar sesión.</p>
      <PatientFailure failure={failure} remaining={remaining} />
      {checked && <Alert variant="info">Se pudo consultar tu perfil con esta sesión. Revisa la solicitud vigente y el código más reciente antes de decidir otro envío. Esta consulta no confirma un cambio de correo ni una entrega.</Alert>}
      {uncertain && <div className="patients-actions"><Button variant="secondary" loading={busy === 'check'} disabled={Boolean(busy)} onClick={async () => {
        const result = await run('check', () => consultarMiPerfil());
        if (result?.data) { setUncertain(false); setDelivery(null); setChecked(true); setCode(''); }
      }}>Comprobar mi sesión y perfil</Button></div>}
    </div>
    <form className="patients-panel patient-form" aria-label="Solicitar cambio de correo" aria-busy={busy === 'request'} onSubmit={submitRequest}>
      <h2>Nueva solicitud</h2><p className="patients-note">Iniciar otra solicitud sustituye la anterior y su código, respetando los límites de envío.</p>
      <FormField name="correo_acceso" label="Nuevo correo de acceso" type="email" autoComplete="email" required maxLength={254} value={email} readOnly={disabled || confirm} error={failure?.fields.correo_acceso} onChange={event => setEmail(event.target.value)} />
      <FormField name="current_password" label="Contraseña actual" type="password" autoComplete="current-password" required maxLength={1024} value={password} readOnly={disabled || confirm} error={failure?.fields.current_password} onChange={event => setPassword(event.target.value)} />
      {confirm && <p role="status" className="patients-note">¿Solicitar el cambio a {email.trim().toLowerCase()}? Se sustituirá cualquier solicitud pendiente. El correo vigente no cambia hasta verificar el nuevo código.</p>}
      <div className="patients-actions"><Button type="submit" loading={busy === 'request'} disabled={disabled || sendRemaining > 0}>{busy === 'request' ? 'Solicitando cambio…' : sendRemaining ? `Espera ${sendRemaining} s para solicitar` : confirm ? 'Confirmar solicitud de cambio' : 'Revisar cambio'}</Button>
        {confirm && <Button variant="quiet" disabled={Boolean(busy)} onClick={() => { setConfirm(false); setPassword(''); }}>Volver a revisar solicitud</Button>}</div>
    </form>
    <section className="patients-panel" aria-labelledby="email-verification"><h2 id="email-verification" ref={heading} tabIndex="-1">Verificar solicitud vigente</h2>
      {delivery && <Alert variant={delivery.envio === 'fallido' ? 'error' : 'info'}><p>{deliveryMessages[delivery.envio]}</p><p>Vencimiento del código emitido: <time dateTime={delivery.expira_en}>{new Intl.DateTimeFormat('es', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(delivery.expira_en))} UTC</time>.</p></Alert>}
      <p className="patients-note">Si ya solicitaste un cambio, puedes continuar aquí aunque hayas vuelto a iniciar sesión. La verificación y el reenvío usan la última solicitud de tu cuenta; escribir otro correo arriba no cambia su destino. Solo sirve el código específico de este cambio, no uno de activación o recuperación.</p>
      <p className="patients-note">El código tiene seis dígitos, dura diez minutos y admite cinco errores. Reenvío: al menos 60 segundos entre emisiones y máximo cinco por hora. El servidor determina su vigencia y los límites.</p>
      <form aria-label="Verificar cambio de correo" aria-busy={busy === 'verify'} onSubmit={async event => {
        event.preventDefault(); if (disabled || remaining || confirm || delivery?.envio === 'fallido') return;
        if (!/^\d{6}$/.test(code)) { report({ message: 'Revisa el código.', fields: { codigo: 'Introduce exactamente seis dígitos, incluidos los ceros iniciales.' } }); return; }
        setChecked(false);
        const result = await run('verify', async () => { try { await completeEmailChange(() => verificarCambioCorreo({ codigo: code })); } finally { setCode(''); setPassword(''); } });
        handleResult(result);
      }}>
        <FormField name="codigo" label="Código de cambio de correo" type="text" inputMode="numeric" autoComplete="one-time-code" required minLength={6} maxLength={6} value={code} readOnly={disabled || confirm} error={failure?.fields.codigo} onChange={event => setCode(event.target.value)} />
        <div className="patients-actions"><Button type="submit" loading={busy === 'verify'} disabled={disabled || remaining > 0 || confirm || delivery?.envio === 'fallido'}>{busy === 'verify' ? 'Verificando cambio…' : 'Verificar y cambiar mi correo'}</Button>
          <Button variant="secondary" disabled={disabled || sendRemaining > 0 || confirm} onClick={async () => {
            setCode(''); setPassword(''); setDelivery(null); setChecked(false);
            handleResult(await run('resend', () => reenviarCambioCorreo()));
          }}>{busy === 'resend' ? 'Solicitando otro código…' : sendRemaining ? `Reenvío en ${sendRemaining} s` : 'Solicitar otro código de cambio'}</Button></div>
      </form>
    </section>
  </section>;
}
