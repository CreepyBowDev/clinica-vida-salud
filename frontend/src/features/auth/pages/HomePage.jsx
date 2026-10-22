import { useAuth } from '../../../hooks/useAuth';
import { AREAS, ROLES } from '../../../constants/roles';
import { navigationForRole } from '../../../constants/navigation';
import { Link } from 'react-router-dom';

export default function HomePage({ area = false }) {
  const { usuario } = useAuth();
  const current = AREAS[usuario.rol];
  const modules = navigationForRole(usuario.rol);
  const available = modules.filter(module => module.items.some(item => item.kind === 'page'));
  const pending = modules.filter(module => module.items.every(item => item.kind === 'pending'));
  const primary = {
    [ROLES.ADMINISTRADOR]: { to: '/administracion/cuentas', label: 'Consultar cuentas de usuario' },
    [ROLES.RECEPCIONISTA]: { to: '/recepcion/pacientes', label: 'Consultar pacientes' },
    [ROLES.PACIENTE]: { to: '/mi-perfil', label: 'Consultar mi perfil' },
    [ROLES.MEDICO]: { to: '/mi-cuenta/contrasena', label: 'Cambiar contraseña' },
  }[usuario.rol];
  return <div className="workspace-page workspace-home">
    <header className="page-heading"><h1>{area ? current.label : `Hola, ${usuario.nombre_visible}`}</h1>
      <p>{area ? current.description : 'Bienvenido a tu espacio en Clínica Vida Salud.'}</p></header>
    <section className="workspace-overview" aria-labelledby="access-title">
      <div className="workspace-intro"><span className="small-cross" aria-hidden="true">+</span>
        <h2 id="access-title">¿Qué necesitas hacer hoy?</h2>
        <p>Accede a las funciones disponibles de tu área. También puedes abrir sus módulos desde el menú de navegación.</p>
        {!area && <Link className="button button--primary" to={current.path}>Ir a {current.label.toLowerCase()}</Link>}
        {area && <Link className="button button--primary" to={primary.to}>{primary.label}</Link>}
      </div>
      <dl className="account-summary"><div><dt>Nombre</dt><dd>{usuario.nombre_visible}</dd></div>
        <div><dt>Correo de acceso</dt><dd>{usuario.correo_acceso}</dd></div>
        <div><dt>Rol</dt><dd><span className="role-badge">{usuario.rol}</span></dd></div></dl>
    </section>
    <section className="home-access" aria-labelledby="available-title"><header><h2 id="available-title">Funciones disponibles</h2><p>Selecciona una tarea para comenzar.</p></header>
      <div className="home-module-grid">{available.map(module => <section key={module.id} className="home-module"><h3>{module.label}</h3>
        <ul>{module.items.map(item => <li key={item.id}><Link to={item.to}>{item.label}</Link></li>)}</ul></section>)}</div>
    </section>
    {pending.length > 0 && <section className="home-pending" aria-labelledby="pending-title"><h2 id="pending-title">Próximos módulos</h2>
      <p>Estas funciones están pendientes de implementación. No están disponibles todavía.</p>
      <ul>{pending.map(module => <li key={module.id}><span>{module.label}</span><span className="role-badge">Pendiente</span></li>)}</ul></section>}
  </div>;
}
