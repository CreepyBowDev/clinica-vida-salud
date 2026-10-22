import { useEffect, useRef, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import Brand from '../components/Brand';
import Button from '../components/Button';
import Icon from '../components/Icon';
import Modal from '../components/Modal';
import '../features/auth/styles/access.css';

const guidance = {
  '/registro': ['Tu cuenta, desde el primer paso.', 'Este registro es para pacientes nuevos. Si ya tienes una ficha, usa la autorización presencial de Recepción.'],
  '/vincular-ficha': ['Tu ficha. El mismo historial.', 'Vincula tu ficha existente con el código de Recepción y verifica después tu correo. No necesitas crear otra ficha.'],
  '/verificar-registro': ['Un paso para cuidar tu acceso.', 'Usa el código de tu correo. El código presencial de Recepción pertenece al paso anterior.'],
  '/olvide-mi-contrasena': ['Vuelve a tu espacio de cuidado.', 'La recuperación usa un enlace enviado a tu correo de acceso, no un código de activación.'],
  '/recuperar-clave': ['Un nuevo acceso, solo tuyo.', 'Establece una nueva contraseña. Después tendrás que volver a iniciar sesión.'],
  '/activar-personal': ['Tu lugar en el equipo.', 'La invitación permite verificar tu correo y establecer tu primera contraseña.'],
  '/clave-obligatoria': ['Completa tu acceso al equipo.', 'La credencial temporal permite únicamente establecer tu contraseña definitiva antes de ingresar al portal.'],
};

export default function AuthLayout() {
  const [help, setHelp] = useState(false);
  const { pathname } = useLocation();
  const main = useRef(null);
  const previousPath = useRef(pathname);
  const context = guidance[pathname];
  useEffect(() => {
    document.documentElement.scrollTop = 0;
    if (previousPath.current !== pathname) main.current?.focus({ preventScroll: true });
    previousPath.current = pathname;
  }, [pathname]);
  return <div className={`auth-shell${pathname === '/login' ? '' : ' auth-shell--flow'}`}>
    <a className="skip-link" href="#contenido">Saltar al contenido</a>
    <header className="auth-header"><Brand /><Button variant="quiet" onClick={() => setHelp(true)}>
      <Icon name="help" /><span>Ayuda para ingresar</span>
    </Button></header>
    <main ref={main} id="contenido" className="auth-main" tabIndex="-1">
      <section className="auth-story" aria-labelledby="story-title">
        <div className="story-copy"><h2 id="story-title">{context ? context[0] : <>Cerca de ti.<br /> En cada paso.</>}</h2>
          <p>{context ? context[1] : 'Un mismo espacio para pacientes y equipo médico. Una atención más conectada.'}</p>
        </div>
        <div className="care-art" aria-hidden="true">
          <svg viewBox="0 0 520 330" fill="none">
            <path d="M90 302V171a170 170 0 0 1 340 0v131" fill="var(--color-art)" />
            <path d="M136 302V171a124 124 0 0 1 248 0v131" fill="var(--color-story)" />
            <rect x="180" y="114" width="160" height="190" rx="18" fill="white" />
            <path d="M250 145h20v24h24v20h-24v24h-20v-24h-24v-20h24z" fill="var(--color-primary)" />
            <path d="M212 244h96m-96 18h65" stroke="var(--color-border)" strokeWidth="8" strokeLinecap="round" />
            <path d="M65 304h390" stroke="var(--color-primary)" strokeOpacity=".25" strokeWidth="2" />
            <circle cx="145" cy="98" r="8" fill="var(--color-primary)" fillOpacity=".28" />
            <path d="M401 231v-50m0 20c-28 0-32-21-32-21 27 0 32 21 32 21Zm0 16c27 0 33-21 33-21-28 0-33 21-33 21Z" stroke="var(--color-primary)" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </div>
        <p className="story-caption"><span aria-hidden="true" className="small-cross">+</span> Tecnología al servicio del cuidado.</p>
      </section>
      <section className="auth-content" aria-label="Acceso a tu cuenta"><Outlet /></section>
    </main>
    <footer className="auth-footer"><span>Clínica Vida Salud</span><span>Atención cercana. Cuidado presente.</span></footer>
    <Modal open={help} onClose={() => setHelp(false)} title="Ayuda para ingresar">
      <p>Usa el correo electrónico de tu cuenta y tu contraseña. El mismo acceso sirve para pacientes y personal de la clínica.</p>
      <h3>Si todavía no tienes acceso</h3>
      <p>Recepción puede orientarte sobre tu ficha y el proceso de activación. El personal recibe una invitación del Administrador.</p>
      <p className="text-muted">Si olvidaste tu contraseña, usa la opción de recuperación con tu correo verificado. Si eres Paciente nuevo, elige «Crear cuenta de Paciente»; si ya tienes una ficha, solicita el código presencial de Recepción y elige «Ya tengo un código de activación».</p>
      <Button className="modal-action" onClick={() => setHelp(false)}>Entendido</Button>
    </Modal>
  </div>;
}
