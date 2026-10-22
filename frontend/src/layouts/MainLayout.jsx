import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { AREAS } from '../constants/roles';
import { navigationAtPath, navigationForRole, navigationTitle } from '../constants/navigation';
import { useAuth } from '../hooks/useAuth';
import Brand from '../components/Brand';
import Icon from '../components/Icon';
import LogoutButton from '../features/auth/components/LogoutButton';
import ModuleNavigation from './ModuleNavigation';

export default function MainLayout() {
  const { usuario } = useAuth();
  const current = AREAS[usuario.rol];
  const location = useLocation();
  const modules = navigationForRole(usuario.rol);
  const active = navigationAtPath(modules, location.pathname);
  const title = navigationTitle(location.pathname, active, current);
  const [menu, setMenu] = useState(false);
  const main = useRef(null);
  const menuButton = useRef(null);
  const menuFocused = useRef(false);
  useEffect(() => {
    const desktop = window.matchMedia?.('(min-width: 961px)');
    if (!desktop) return;
    const closeMenu = () => {
      setMenu(false);
      if (!desktop.matches && document.activeElement?.closest('#workspace-navigation')) menuButton.current?.focus();
      if (desktop.matches && (document.activeElement === menuButton.current || menuFocused.current)) {
        const navigation = document.getElementById('workspace-navigation');
        const currentLink = navigation?.querySelector('[aria-current="page"]');
        (currentLink && !currentLink.closest('[hidden]') ? currentLink : navigation?.querySelector('a'))?.focus();
        menuFocused.current = false;
      }
    };
    desktop.addEventListener('change', closeMenu);
    return () => desktop.removeEventListener('change', closeMenu);
  }, []);
  useEffect(() => {
    main.current?.focus({ preventScroll: true });
    document.documentElement.scrollTop = 0;
    document.title = `${title} | Clínica Vida Salud`;
    setMenu(false);
  }, [location.pathname, title, usuario.id_usuario, usuario.rol]);

  function closeNavigation(event) {
    setMenu(false);
    if (event.currentTarget.getAttribute('href') === location.pathname) main.current?.focus({ preventScroll: true });
  }

  return <div className="workspace-shell">
    <a className="skip-link" href="#contenido" onClick={event => { event.preventDefault(); main.current?.focus(); }}>Saltar al contenido</a>
    <aside className="workspace-sidebar">
      <div className="sidebar-heading"><Brand /><button ref={menuButton} type="button" className="icon-button mobile-menu"
        onFocus={() => { menuFocused.current = true; }} onBlur={() => { if (!window.matchMedia?.('(min-width: 961px)').matches) menuFocused.current = false; }}
        aria-expanded={menu} aria-controls="workspace-navigation" aria-label={menu ? 'Cerrar menú' : 'Abrir menú'} onClick={() => setMenu(!menu)}>
        <Icon name={menu ? 'close' : 'menu'} /></button></div>
      <nav id="workspace-navigation" aria-label="Navegación principal" className={`workspace-nav ${menu ? 'is-open' : ''}`}
        onKeyDown={(event) => { if (event.key === 'Escape') { setMenu(false); menuButton.current?.focus(); } }}>
        <NavLink to="/inicio" onClick={closeNavigation}><Icon name="home" />Inicio</NavLink>
        <NavLink to={current.path} end onClick={closeNavigation}><Icon name="grid" />{current.label}</NavLink>
        <ModuleNavigation key={`${usuario.id_usuario}:${usuario.rol}`} modules={modules} active={active} pathname={location.pathname} onNavigate={closeNavigation} />
      </nav>
      <div className="sidebar-caption"><span className="small-cross" aria-hidden="true">+</span><p>Atención cercana.<br />Cuidado presente.</p></div>
    </aside>
    <div className="workspace-body"><header className="workspace-header"><div className="workspace-context"><Icon name={active?.module.icon ?? 'grid'} /><div><span>{current.label}</span><strong>{active?.module.label ?? title}</strong></div></div>
      <div className="workspace-identity"><span className="user-avatar" aria-hidden="true">{usuario.nombre_visible.slice(0, 1).toUpperCase()}</span>
        <div><strong>{usuario.nombre_visible}</strong><span>{usuario.rol}</span></div></div></header>
      <main id="contenido" className="workspace-main" ref={main} tabIndex="-1">
        {active && <nav className="workspace-breadcrumb" aria-label="Ubicación actual"><ol><li><Link to={current.path}>{current.label}</Link></li>
          <li>{active.module.label}</li>{location.pathname.replace(/\/$/, '') !== active.item.to && <li><Link to={active.item.to} aria-label={`Volver a ${active.item.label.toLowerCase()}`}>{active.item.label}</Link></li>}
          <li aria-current="page">{title}</li></ol></nav>}<Outlet /></main>
      <footer className="workspace-footer"><span>Clínica Vida Salud</span><LogoutButton /></footer>
    </div>
  </div>;
}
