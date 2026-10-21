import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { AREAS, ROLES } from '../constants/roles';
import { useAuth } from '../hooks/useAuth';
import Brand from '../components/Brand';
import Icon from '../components/Icon';
import LogoutButton from '../features/auth/components/LogoutButton';

export default function MainLayout() {
  const { usuario } = useAuth();
  const current = AREAS[usuario.rol];
  const location = useLocation();
  const [menu, setMenu] = useState(false);
  const main = useRef(null);
  const menuButton = useRef(null);
  useEffect(() => {
    const desktop = window.matchMedia?.('(min-width: 961px)');
    if (!desktop) return;
    const closeMenu = () => setMenu(false);
    desktop.addEventListener('change', closeMenu);
    return () => desktop.removeEventListener('change', closeMenu);
  }, []);
  useEffect(() => {
    main.current?.focus({ preventScroll: true });
    document.documentElement.scrollTop = 0;
    const title = location.pathname.startsWith('/administracion/especialidades') ? 'Especialidades' : location.pathname.startsWith('/administracion/medicos') ? 'Médicos' : location.pathname === '/mi-perfil/correo' ? 'Cambiar correo de acceso' : location.pathname === '/mi-perfil' ? 'Mi perfil' : location.pathname.startsWith('/recepcion/pacientes') ? 'Pacientes' : location.pathname.startsWith('/administracion/cuentas') ? 'Cuentas de usuario' : location.pathname === '/recepcion/activacion-web' ? 'Activación web de Paciente' : location.pathname === '/mi-cuenta/contrasena' ? 'Cambiar contraseña' : location.pathname === '/inicio' ? 'Inicio' : current.label;
    document.title = `${title} | Clínica Vida Salud`;
  }, [location.pathname, current.label]);

  return <div className="workspace-shell">
    <a className="skip-link" href="#contenido">Saltar al contenido</a>
    <aside className="workspace-sidebar">
      <div className="sidebar-heading"><Brand /><button ref={menuButton} type="button" className="icon-button mobile-menu"
        aria-expanded={menu} aria-controls="workspace-navigation" aria-label={menu ? 'Cerrar menú' : 'Abrir menú'} onClick={() => setMenu(!menu)}>
        <Icon name={menu ? 'close' : 'menu'} /></button></div>
      <nav id="workspace-navigation" aria-label="Navegación principal" className={`workspace-nav ${menu ? 'is-open' : ''}`}
        onKeyDown={(event) => { if (event.key === 'Escape') { setMenu(false); menuButton.current?.focus(); } }}>
        <NavLink to="/inicio" onClick={() => setMenu(false)}><Icon name="home" />Inicio</NavLink>
        <NavLink to={current.path} end onClick={() => setMenu(false)}><Icon name="grid" />{current.label}</NavLink>
        {usuario.rol === ROLES.PACIENTE && <NavLink to="/mi-perfil" onClick={() => setMenu(false)}><Icon name="user" />Mi perfil</NavLink>}
        {usuario.rol === ROLES.ADMINISTRADOR && <NavLink to="/administracion/cuentas" onClick={() => setMenu(false)}><Icon name="people" />Cuentas de usuario</NavLink>}
        {usuario.rol === ROLES.ADMINISTRADOR && <NavLink to="/administracion/medicos" onClick={() => setMenu(false)}><Icon name="people" />Médicos</NavLink>}
        {usuario.rol === ROLES.ADMINISTRADOR && <NavLink to="/administracion/especialidades" onClick={() => setMenu(false)}><Icon name="grid" />Especialidades</NavLink>}
        {usuario.rol === ROLES.RECEPCIONISTA && <NavLink to="/recepcion/pacientes" onClick={() => setMenu(false)}><Icon name="people" />Pacientes</NavLink>}
        {usuario.rol === ROLES.RECEPCIONISTA && <NavLink to="/recepcion/activacion-web" onClick={() => setMenu(false)}><Icon name="lock" />Activación web</NavLink>}
        <NavLink to="/mi-cuenta/contrasena" onClick={() => setMenu(false)}><Icon name="lock" />Cambiar contraseña</NavLink>
      </nav>
      <div className="sidebar-caption"><span className="small-cross" aria-hidden="true">+</span><p>Atención cercana.<br />Cuidado presente.</p></div>
    </aside>
    <div className="workspace-body"><header className="workspace-header"><span className="workspace-context"><Icon name="grid" />{current.label}</span>
      <div className="workspace-identity"><span className="user-avatar" aria-hidden="true">{usuario.nombre_visible.slice(0, 1).toUpperCase()}</span>
        <div><strong>{usuario.nombre_visible}</strong><span>{usuario.rol}</span></div></div></header>
      <main id="contenido" className="workspace-main" ref={main} tabIndex="-1"><Outlet /></main>
      <footer className="workspace-footer"><span>Clínica Vida Salud</span><LogoutButton /></footer>
    </div>
  </div>;
}
